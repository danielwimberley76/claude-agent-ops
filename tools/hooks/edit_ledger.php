<?php
/**
 * PostToolUse (Write|Edit|NotebookEdit) hook — record which session wrote which file.
 *
 * Appends one JSON line per edit to .claude/session-ledger/<session_id>.jsonl (gitignored) in
 * the MAIN checkout, so tools/my_changes.php can answer "which uncommitted paths are mine?" at
 * wrap-up and "whose are these?" at end of day.
 *
 * Why: several sessions share this checkout, and a session had no record of what it wrote, so
 * it claimed its uncommitted files only when the owner remembered to ask. The owner's last
 * session of the day inherited the rest.
 *
 * Paths are stored ABSOLUTE (normalised to forward slashes, lower-cased), so worktree edits are
 * recorded too; my_changes.php matches them against whichever checkout it runs in. Bash-mediated
 * writes (sed -i, scripts) are not seen here — they surface as "unattributed" rather than
 * silently as nobody's. tools/board.py appends its own writes to this same ledger.
 *
 * On a session's FIRST edit it injects its ledger id once, so the session can run
 * `php tools/my_changes.php --session <id>` at wrap-up. Always exits 0.
 */

try {
    $raw = stream_get_contents(STDIN);
    if ($raw === false || $raw === '') {
        exit(0);
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        exit(0);
    }

    $input = $payload['tool_input'] ?? [];
    $filePath = $input['file_path'] ?? $input['notebook_path'] ?? '';
    $sessionId = (string)($payload['session_id'] ?? '');
    if (!is_string($filePath) || $filePath === '' || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $sessionId)) {
        exit(0);
    }

    $root = getenv('CLAUDE_PROJECT_DIR');
    $root = ($root !== false && $root !== '') ? rtrim($root, '/\\') : getcwd();
    $dir = $root . '/.claude/session-ledger';
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        exit(0);
    }

    $ledger = $dir . '/' . $sessionId . '.jsonl';
    // "First" = no meta row yet, not "no file": tools/board.py also appends here, with no meta
    // row, and may be the session's first write.
    $first = !is_file($ledger) || strpos((string)@file_get_contents($ledger), '"meta":1') === false;

    $lines = '';
    if ($first) {
        // Meta line: lets my_changes.php label the session by its opening prompt.
        $lines .= json_encode([
            'meta' => 1,
            't' => date('c'),
            'transcript' => (string)($payload['transcript_path'] ?? ''),
            'cwd' => (string)($payload['cwd'] ?? ''),
        ], JSON_UNESCAPED_SLASHES) . "\n";
    }
    $lines .= json_encode(['t' => date('c'), 'p' => ledger_norm($filePath)], JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($ledger, $lines, FILE_APPEND | LOCK_EX);

    if ($first) {
        echo json_encode([
            'hookSpecificOutput' => [
                'hookEventName' => 'PostToolUse',
                'additionalContext' => "Edit ledger: this session's file writes are recorded under id {$sessionId}. "
                    . "At wrap-up, before saying you're done, run `php tools/my_changes.php --session {$sessionId}` "
                    . "and commit (or explicitly hand off) every path it lists as yours. Paths you changed through Bash "
                    . "(sed, scripts) are not recorded, so check those yourself.",
            ],
            'suppressOutput' => true,
        ]);
    }
    exit(0);
} catch (\Throwable $e) {
    exit(0);
}

/** "C:\x\y" and "/c/x/y" → "c:/x/y" — one comparable spelling. Same shape as my_changes.php. */
function ledger_norm(string $p): string
{
    $p = str_replace('\\', '/', $p);
    if (preg_match('~^/([a-zA-Z])/(.*)$~', $p, $m)) {
        $p = $m[1] . ':/' . $m[2];
    }
    return strtolower($p);
}
