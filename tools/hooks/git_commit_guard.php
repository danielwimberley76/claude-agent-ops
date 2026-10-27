<?php
/**
 * Bash/PowerShell-tool guard for git shapes that fail silently IN A SHARED WORKING TREE.
 *
 * Several Claude Code sessions commit to the same checkout. Each failure below was observed
 * in real sessions before this hook existed:
 *   1. A PowerShell here-string (@'...'@) used in the Bash tool. Bash concatenates it instead
 *      of erroring, so the commit lands with a bare @ as its subject.
 *   2. `--amend` racing a concurrent session. If another session commits between your commit
 *      and your amend, --amend rewrites THEIR commit.
 *   3. A BROAD `git add` (-A / --all / -u / `.` / `*`) — it stages every other live session's
 *      uncommitted work along with yours. Once, the swept file reached prod AHEAD OF ITS OWN
 *      MIGRATION and left a form silently unable to open.
 *   4. `git commit -a` / `-am`, which is the same sweep with the staging step folded in.
 *   5. `git stash` with no pathspec / `git stash clear` — the sweep with files taken OFF DISK.
 *   6. An app-code commit whose subject carries no `#N:` board id. Not silent in git, but silent
 *      in the DEPLOY: the deploy planner finds a task's work only by that prefix, so the commit
 *      could ship only by sha. Override CC_NO_TASK_OK=1.
 *
 * Wiring (.claude/settings.json): PreToolUse -> `pre`, PostToolUse -> `post`, on BOTH the Bash
 * tool ("if": "Bash(git *)") and the PowerShell tool ("if": "PowerShell(*git*)"). `post` records
 * HEAD after each commit; `pre` refuses an amend when HEAD no longer matches that record.
 *
 * Rationale: .claude/decisions.md#git-commit-guard-hook
 */
declare(strict_types=1);

$mode = $argv[1] ?? 'pre';
$data = json_decode((string)stream_get_contents(STDIN), true) ?: [];
$cmd = (string)($data['tool_input']['command'] ?? '');

/**
 * Every check is about what git is asked to do, so it applies to both shells — EXCEPT the two
 * message-shape checks (here-string, multi-line -m), which exist because Bash mangles those
 * shapes. In PowerShell a here-string is the correct, working form. Quoting differs too:
 * PowerShell escapes with a backtick and doubles a single quote, and a backslash is a literal.
 */
$isPs = ($data['tool_name'] ?? '') === 'PowerShell';
$quoteRe = $isPs
    ? '/@\'\r?\n.*?\r?\n\'@|@"\r?\n.*?\r?\n"@|"(?:[^"`]|`.)*"|\'(?:[^\']|\'\')*\'/s'
    : '/"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'/';

/**
 * `git` must sit at a COMMAND POSITION — start of line, or after && || ; | or a newline,
 * optionally behind env assignments (CC_AMEND_OK=1 git …).
 *
 * ⛔ Without this the guard reads its own name in someone's grep: `git diff | grep "git commit -a"`
 *    is read-only and was refused. A guard that fires on ordinary work is worse than no guard:
 *    it teaches the override.
 */
const CMD_POS = '(?:^|&&|\|\||;|\||\n)\s*(?:[A-Za-z_][A-Za-z0-9_]*=\S*\s+)*';

/**
 * ⛔ AND QUOTED SPANS ARE BLANKED FIRST, because command position alone is NOT enough.
 *    `grep -vi "git add|git commit -a"` carries a pipe INSIDE the quotes, which manufactures a
 *    command position out of thin air. Collapsing every quoted span to a placeholder removes the
 *    whole class: a flag like -am lives outside the quotes and still matches, while anything a
 *    grep or an echo is merely TALKING about disappears.
 *    ⚠️ Known and accepted loss: `git add "."` (quoted) is not caught.
 */
$scan = preg_replace($quoteRe, 'Q', $cmd) ?? $cmd;

$subcmdRe = static function (string $sub): string {
    return '/' . CMD_POS . 'git\b(?:\s+-\S+(?:\s+\S+)?)*\s+' . preg_quote($sub, '/') . '\b/';
};

$isCommit = (bool)preg_match($subcmdRe('commit'), $scan);
$isAdd    = (bool)preg_match($subcmdRe('add'), $scan);
$isStash  = (bool)preg_match($subcmdRe('stash'), $scan);
if (!$isCommit && !$isAdd && !$isStash) {
    exit(0);
}

$project = getenv('CLAUDE_PROJECT_DIR') ?: '.';
$session = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($data['session_id'] ?? '')) ?: 'nosession';
$stateFile = rtrim($project, "/\\") . '/.git/cc_commit_head_' . $session;

$head = static function () use ($project): string {
    // shell_exec goes through cmd.exe on Windows even when the hook runs under Git Bash,
    // so the null device has to be chosen by platform.
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $out = @shell_exec('git -C ' . escapeshellarg($project) . ' rev-parse HEAD 2>' . $null);
    return trim((string)$out);
};

// PostToolUse: remember the commit this session just made, so a later amend can prove HEAD is
// still ours. ⛔ COMMITS ONLY — recording HEAD after a bare `git add` would write somebody
// else's commit into this session's "my commit" file and silently INVERT the amend guard.
if ($mode === 'post') {
    if (!$isCommit) {
        exit(0);
    }
    $h = $head();
    if ($h !== '' && is_dir(dirname($stateFile))) {
        @file_put_contents($stateFile, $h);
    }
    exit(0);
}

$deny = static function (string $reason): void {
    echo json_encode([
        'hookSpecificOutput' => [
            'hookEventName' => 'PreToolUse',
            'permissionDecision' => 'deny',
            'permissionDecisionReason' => $reason,
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit(0);
};

$writeToFile = 'Write the message to a file with the Write tool, then: '
    . 'git commit -F "<scratchpad>/commit_msg.txt" -- <paths>. '
    . 'The Write tool passes content as JSON, so no shell parsing can mangle it.';

/**
 * The argument tokens of one git subcommand, stopping at a shell separator so a later
 * `&& git status .` cannot be read as this command's pathspec.
 *
 * @return string[]
 */
$argsFor = static function (string $cmd, string $sub): array {
    $re = '/' . CMD_POS . 'git\b(?:\s+-\S+(?:\s+\S+)?)*\s+' . preg_quote($sub, '/') . '\b(.*)$/s';
    if (!preg_match($re, $cmd, $m)) {
        return [];
    }
    $rest = preg_split('/\s*(?:&&|\|\||;|\||\n)/', $m[1], 2)[0] ?? '';
    $toks = preg_split('/\s+/', trim($rest), -1, PREG_SPLIT_NO_EMPTY);
    return $toks ?: [];
};

/** Does this token cluster carry one of these SHORT flags? Handles `-A`, `-vA`, `-am`. */
$hasShort = static function (array $tokens, string $letters): bool {
    foreach ($tokens as $t) {
        if ($t === '--' || strlen($t) < 2 || $t[0] !== '-' || $t[1] === '-') {
            continue;
        }
        if (strpbrk(substr($t, 1), $letters) !== false) {
            return true;
        }
    }
    return false;
};

$hasLong = static function (array $tokens, array $names): bool {
    foreach ($tokens as $t) {
        if ($t === '--') {
            break;
        }
        if (in_array(explode('=', $t)[0], $names, true)) {
            return true;
        }
    }
    return false;
};

// ── The shared-tree sweep. Several sessions share this working copy, so "everything modified"
// ── is never "everything I modified". Override: prefix CC_ADD_ALL_OK=1
$sweepOverride = strpos($cmd, 'CC_ADD_ALL_OK=1') !== false;

$sweepFix = 'List the paths you actually touched instead — git add <path> <path>, or '
    . 'git commit -F <msgfile> -- <path> <path>. `git status --porcelain` shows which are yours. '
    . 'If you genuinely mean every modified file in the repo, prefix the command with CC_ADD_ALL_OK=1';

if ($isAdd && !$sweepOverride) {
    $args = $argsFor($scan, 'add');
    $broadPath = (bool)array_intersect($args, ['.', './', '*', ':/', '-A.']);

    if ($hasShort($args, 'Au') || $hasLong($args, ['--all', '--update', '--no-ignore-removal']) || $broadPath) {
        $deny('Broad `git add` in a SHARED working tree — it stages every other live session\'s '
            . 'uncommitted work along with yours, and commits it under your message. This has '
            . 'shipped another session\'s file to prod ahead of its own migration. ' . $sweepFix);
    }
}

if ($isCommit && !$sweepOverride) {
    $args = $argsFor($scan, 'commit');

    if ($hasShort($args, 'a') || $hasLong($args, ['--all'])) {
        $deny('`git commit -a/--all` in a SHARED working tree — it stages every tracked file any '
            . 'session has modified, not just yours. ' . $sweepFix);
    }
}

// ── `git stash` is the same sweep with the files taken OFF DISK, which is strictly worse: a
// ── sweep puts your files in somebody's commit, a stash makes THEIR files vanish from their
// ── editor mid-edit. Override: prefix CC_STASH_OK=1
//
// ⚠️ NARROW ON PURPOSE. Only creating a stash with no pathspec, and `clear`, are guarded.
// `drop` is not: a stash ref has braces so it is always quoted, $scan blanks it, and a wrongly
// dropped stash is recoverable via `git fsck --unreachable` — a vanished working file is not.
if ($isStash && strpos($cmd, 'CC_STASH_OK=1') === false) {
    $args = $argsFor($scan, 'stash');

    // First non-flag token is the stash subcommand; a bare `git stash` means `push`.
    $sub = 'push';
    foreach ($args as $t) {
        if ($t === '--') {
            break;
        }
        if ($t[0] !== '-') {
            $sub = $t;
            break;
        }
    }

    $dashdash    = array_search('--', $args, true);
    $hasPathspec = $dashdash !== false && count($args) > (int)$dashdash + 1;

    if ($sub === 'clear') {
        $deny('`git stash clear` destroys EVERY stash entry in this repo, and they are not all '
            . 'yours. Drop the one you mean by name instead: git stash drop "stash@{0}". '
            . 'If you are certain, prefix with CC_STASH_OK=1');
    }

    if (in_array($sub, ['push', 'save'], true) && !$hasPathspec) {
        $deny('`git stash` with no pathspec shelves EVERY modified tracked file in a shared '
            . 'working tree — it takes other sessions\' in-flight edits off the disk. Name your paths: '
            . 'git stash push -- <path> <path>. If you are certain, prefix the command with CC_STASH_OK=1');
    }
}

if (!$isPs && strpos($cmd, "@'") !== false) {
    $deny("PowerShell here-string (@'...'@) in the Bash tool. Bash concatenates rather than "
        . "erroring, leaving a bare @ as the commit subject. " . $writeToFile);
}

if (!$isPs && preg_match('/(?:^|\s)-m(?:\s|=)/', $cmd) && strpos($cmd, "\n") !== false) {
    $deny('Multi-line commit message passed through -m. Shell quoting is the failure mode. ' . $writeToFile);
}

// ── An APP-CODE commit with no board id. The deploy planner finds a task's work only by its
// ── subject prefix (`#N` / `Merge task/N`), so an app commit without one can only ship by sha.
//
// ⚠️ FAILS OPEN on purpose: when the subject cannot be read (no -m/-F, an editor, an unreadable
// file) or the file list cannot be determined, the commit goes through. This guard catches the
// common shape; it is not a gate a session has to fight.
// Reads $cmd, not $scan — the message and the pathspec are exactly the quoted spans $scan blanks.
if ($isCommit && strpos($cmd, 'CC_NO_TASK_OK=1') === false && strpos($cmd, '--amend') === false) {
    require_once __DIR__ . '/app_paths.php';
    require_once __DIR__ . '/commit_args.php';

    $cwd = commit_cwd($cmd, (string)($data['cwd'] ?? '') ?: $project);
    ['subject' => $subject, 'paths' => $paths] = commit_message_and_paths(commit_tokens($cmd, $isPs), $cwd);

    if ($subject !== null) {
        // git's subject is the first non-blank line.
        foreach (preg_split('/\r?\n/', $subject) as $line) {
            if (trim($line) !== '') {
                $subject = trim($line);
                break;
            }
        }
    }

    if ($subject !== null && !preg_match('/^(?:#\d+\b|Merge task\/\d+\b)/', $subject)) {
        if (!$paths) {
            // No pathspec: the commit takes whatever is staged.
            $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
            $out = @shell_exec('git -C ' . escapeshellarg($cwd) . ' diff --cached --name-only 2>' . $null);
            $paths = preg_split('/\r?\n/', trim((string)$out), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        // A trailing slash so a bare directory pathspec (`src/Foo`) is judged too.
        $app = array_values(array_filter($paths, static fn($p) => hook_is_app_path(rtrim($p, '/\\') . '/')));
        if ($app) {
            $shown = implode(', ', array_slice($app, 0, 3)) . (count($app) > 3 ? ', +' . (count($app) - 3) . ' more' : '');
            $deny("App-code commit with no board id in its subject (\"{$subject}\"). It touches {$shown}, "
                . 'which the app loads, so it is deployable — and the deploy planner finds a task\'s work '
                . 'ONLY by the subject prefix (`#N` / `Merge task/N`). Without one this commit can ship only '
                . 'by its sha, and nothing on the board says it is owed. Fix: start the subject with `#<id>: `. '
                . 'If the task is not shaped yet, `python tools/board.py --new-provisional "<working title>"` '
                . 'allocates an id and claims it. Deliberately ad-hoc work: prefix the command with CC_NO_TASK_OK=1.');
        }
    }
}

if (strpos($cmd, '--amend') !== false) {
    if (strpos($cmd, 'CC_AMEND_OK=1') !== false) {
        exit(0); // deliberate override
    }
    $now = $head();
    $recorded = is_file($stateFile) ? trim((string)file_get_contents($stateFile)) : '';

    if ($recorded === '') {
        $deny('git commit --amend, but this session has no commit on record — HEAD is not yours '
            . 'to rewrite. Multiple sessions commit to main in this repo. Make a NEW commit '
            . 'instead. If you are certain, prefix the command with CC_AMEND_OK=1');
    }
    if ($recorded !== $now) {
        $deny("HEAD moved since your commit: you committed {$recorded}, HEAD is now {$now}. "
            . 'Another session committed in the gap — --amend would rewrite THEIR commit with '
            . 'your message. Make a NEW commit instead. If you are certain, prefix with CC_AMEND_OK=1');
    }
}

exit(0);
