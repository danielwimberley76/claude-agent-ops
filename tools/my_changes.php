<?php
declare(strict_types=1);

/**
 * my_changes.php — who owns each uncommitted path in this checkout.
 *
 * Reads the per-session edit ledgers written by tools/hooks/edit_ledger.php
 * (.claude/session-ledger/*.jsonl in the MAIN checkout) and matches them against `git status`
 * of the checkout it runs in.
 *
 *   php tools/my_changes.php --session <id>   # wrap-up: MY uncommitted paths
 *   php tools/my_changes.php                  # end of day: every dirty path, by session
 *
 * --session exits 1 while any of that session's paths are still uncommitted, so "clean" is
 * checkable. A path another session ALSO edited is flagged SHARED: commit only your hunks.
 *
 * Unattributed = dirty, but no ledger recorded an Edit/Write to it: a Bash-mediated write
 * (sed -i, a script, test artefacts) or a human edit. Those need a person.
 */

chdir(dirname(__DIR__));

$session = null;
foreach (array_slice($argv, 1) as $i => $arg) {
    if (preg_match('/^--session=(.+)$/', $arg, $m)) {
        $session = $m[1];
    } elseif ($arg === '--session') {
        $session = $argv[$i + 2] ?? null;
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "Usage: php tools/my_changes.php [--session <id>]\n";
        exit(0);
    }
}

$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$top = trim((string) shell_exec('git rev-parse --show-toplevel 2>' . $null));
$common = trim((string) shell_exec('git rev-parse --path-format=absolute --git-common-dir 2>' . $null));
if ($top === '' || $common === '') {
    fwrite(STDERR, "Not inside a git checkout.\n");
    exit(2);
}
$mainRoot = dirname($common);               // the main checkout, even from a worktree
$ledgerDir = $mainRoot . '/.claude/session-ledger';

// Dirty paths in THIS checkout, one entry per file (-uall: untracked dirs expanded).
$dirty = [];
$z = (string) shell_exec('git status --porcelain=v1 -z -uall');
$parts = $z === '' ? [] : explode("\0", rtrim($z, "\0"));
for ($i = 0; $i < count($parts); $i++) {
    $entry = $parts[$i];
    if (strlen($entry) < 4) {
        continue;
    }
    $code = substr($entry, 0, 2);
    $rel = substr($entry, 3);
    if ($code[0] === 'R' || $code[0] === 'C') {
        $i++;                                // -z rename: the next field is the source path
    }
    $dirty[norm($top . '/' . $rel)] = ['rel' => $rel, 'code' => $code];
}

// Ledgers → path => [session => last edit time]; session => meta.
$owners = [];
$meta = [];
foreach (glob($ledgerDir . '/*.jsonl') ?: [] as $file) {
    $sid = basename($file, '.jsonl');
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (!is_array($row)) {
            continue;
        }
        if (!empty($row['meta'])) {
            $meta[$sid] = $row;
            continue;
        }
        if (isset($row['p'], $dirty[$row['p']])) {
            $owners[$row['p']][$sid] = $row['t'];
        }
    }
}

echo 'Checkout: ' . $top . ' — ' . count($dirty) . " uncommitted path(s)\n\n";

if ($session !== null) {
    $mine = array_filter($owners, fn(array $s) => isset($s[$session]));
    if ($mine === []) {
        echo "Session {$session}: nothing of yours is uncommitted here.\n";
        exit(0);
    }
    echo "Session {$session} — " . count($mine) . " uncommitted path(s) you edited:\n";
    foreach ($mine as $p => $sessions) {
        $others = array_diff(array_keys($sessions), [$session]);
        printf("  %s %s%s\n", $dirty[$p]['code'], $dirty[$p]['rel'],
            $others ? '   ⚠ SHARED with ' . implode(', ', array_map('shortId', $others)) . ' — commit only your hunks' : '');
    }
    echo "\nCommit them (git commit -F <msg> -- <paths>) or hand them off explicitly before finishing.\n";
    exit(1);
}

// End-of-day view: group by owning session(s).
$bySession = [];
$unattributed = [];
foreach ($dirty as $p => $d) {
    if (!isset($owners[$p])) {
        $unattributed[] = $d;
        continue;
    }
    $sids = array_keys($owners[$p]);
    sort($sids);
    $bySession[implode(' + ', $sids)][] = $d;
}
ksort($bySession);
foreach ($bySession as $key => $paths) {
    $sids = explode(' + ', $key);
    echo (count($sids) > 1 ? 'SHARED by ' : 'Session ') . implode(' + ', $sids) . "\n";
    foreach ($sids as $sid) {
        $label = sessionLabel($meta[$sid] ?? null);
        if ($label !== '') {
            echo '  ' . shortId($sid) . ": {$label}\n";
        }
    }
    foreach ($paths as $d) {
        echo "    {$d['code']} {$d['rel']}\n";
    }
    echo "\n";
}
if ($unattributed !== []) {
    echo "Unattributed (no Edit/Write recorded: Bash-written, generated, or a human edit):\n";
    foreach ($unattributed as $d) {
        echo "    {$d['code']} {$d['rel']}\n";
    }
    echo "\n";
}
if ($bySession !== []) {
    echo "Resume an owning session with: claude --resume <session id>\n";
}

function norm(string $p): string
{
    $p = str_replace('\\', '/', $p);
    if (preg_match('~^/([a-zA-Z])/(.*)$~', $p, $m)) {
        $p = $m[1] . ':/' . $m[2];
    }
    return strtolower($p);
}

function shortId(string $sid): string
{
    return substr($sid, 0, 8);
}

/** "started 10-07 09:12 — <first prompt, 90 chars>" from the session's transcript. */
function sessionLabel(?array $meta): string
{
    if ($meta === null) {
        return '';
    }
    $when = isset($meta['t']) ? date('m-d H:i', (int) strtotime($meta['t'])) : '?';
    $prompt = '';
    $tx = (string) ($meta['transcript'] ?? '');
    if ($tx !== '' && is_file($tx) && ($fh = fopen($tx, 'rb')) !== false) {
        for ($n = 0; $n < 200 && ($line = fgets($fh)) !== false; $n++) {
            $row = json_decode($line, true);
            if (($row['type'] ?? '') !== 'user') {
                continue;
            }
            $c = $row['message']['content'] ?? '';
            if (is_array($c)) {
                $c = implode(' ', array_map(fn($b) => is_array($b) && ($b['type'] ?? '') === 'text' ? (string) $b['text'] : '', $c));
            }
            $c = trim((string) preg_replace('/<[^>]+>[\s\S]*?<\/[^>]+>|\s+/', ' ', (string) $c));
            if ($c !== '') {
                $prompt = mb_strlen($c) > 90 ? mb_substr($c, 0, 87) . '...' : $c;
                break;
            }
        }
        fclose($fh);
    }
    return "started {$when}" . ($prompt !== '' ? " — \"{$prompt}\"" : '');
}
