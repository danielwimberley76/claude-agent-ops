<?php
/**
 * Stop hook — a session may not finish with its own uncommitted files unmentioned.
 *
 * When the model tries to end a turn, this asks tools/my_changes.php which paths THIS session
 * wrote (per the edit ledger from edit_ledger.php) are still uncommitted. If any are, it blocks
 * the stop ONCE and lists them. The session then commits, or says why it is holding, and stops.
 *
 * It blocks again only when that set CHANGES, so pausing to ask a question costs at most one
 * extra step per change. The last-blocked fingerprint lives beside the ledger as <session>.stop.
 *
 * Why: wrap-up steps written as prose were skipped; every other stage of a session had a hook.
 * Extension point: add a second section for anything else a session must surface before it
 * finishes (e.g. app error-log lines written since its first edit).
 *
 * Always exits 0 — a broken guard must never trap a session.
 */

try {
    $payload = json_decode((string) stream_get_contents(STDIN), true);
    $sessionId = is_array($payload) ? (string) ($payload['session_id'] ?? '') : '';
    if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $sessionId)) {
        exit(0);
    }

    $root = getenv('CLAUDE_PROJECT_DIR');
    $root = ($root !== false && $root !== '') ? rtrim(str_replace('\\', '/', $root), '/') : str_replace('\\', '/', getcwd());
    $ledgerDir = "{$root}/.claude/session-ledger";
    if (!is_file("{$ledgerDir}/{$sessionId}.jsonl")) {
        exit(0);   // made no edits: nothing of its own to leave behind
    }

    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/tools/my_changes.php")
        . ' --session ' . escapeshellarg($sessionId) . ' 2>&1');
    $paths = [];
    foreach (preg_split('/\R/', $out) as $line) {
        if (preg_match('/^  (.{2}) (.+)$/', $line)) {   // "  ?? path", "   M path"
            $paths[] = '  ' . trim($line);
        }
    }

    $stateFile = "{$ledgerDir}/{$sessionId}.stop";
    if ($paths === []) {
        @unlink($stateFile);
        exit(0);
    }

    $hash = sha1(implode('|', $paths));
    if (is_file($stateFile) && trim((string) file_get_contents($stateFile)) === $hash) {
        exit(0);   // already flagged exactly this; the session has had its say
    }
    @file_put_contents($stateFile, $hash);

    $reason = "Wrap-up guard: before finishing, deal with what this session left behind.\n\n"
        . "Uncommitted files you wrote:\n" . implode("\n", $paths) . "\n\n"
        . "Commit them (`git commit -F <msg> -- <paths>`, `#<id>:` subject), or tell the owner why they are held. "
        . "Never commit a SHARED path's other-session hunks. If you are only pausing to ask something, say so "
        . "and stop again; this guard won't repeat for the same files.";

    echo json_encode(['decision' => 'block', 'reason' => $reason], JSON_UNESCAPED_SLASHES);
    exit(0);
} catch (\Throwable $e) {
    exit(0);
}
