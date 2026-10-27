<?php
/**
 * PostToolUse (Write|Edit) hook — knowledge-base invariant check.
 *
 * When a `.claude/*.md` doc (or CLAUDE.md) is written, runs `tools/kb_check.py --quiet` and
 * injects any failures back into the model's context so they surface in the same turn that
 * introduced them.
 *
 * Why a hook and not a rule in CLAUDE.md: this has to fire on an EVENT. A check someone has to
 * remember is a check that does not happen.
 *
 * Behaviour notes:
 *  - Only fires for writes under `.claude/` (plus CLAUDE.md). Everything else exits silently.
 *  - REPORTS, never blocks. A doc edit mid-refactor is legitimately inconsistent for a few tool
 *    calls; blocking would make the checker an obstacle and the first response would be to
 *    disable it.
 *  - Debounced per session+failure-set: the same failure list is injected once, so a ten-file
 *    refactor does not repeat it ten times. A NEW failure always surfaces.
 *  - Always exits 0; any internal error is swallowed so a hook hiccup never interrupts a session.
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

    $filePath = (string)($payload['tool_input']['file_path'] ?? '');
    if ($filePath === '') {
        exit(0);
    }

    $norm = str_replace('\\', '/', $filePath);
    $isDoc = (bool)preg_match('#(^|/)\.claude/[^/]+\.md$#', $norm)
        || (bool)preg_match('#(^|/)\.claude/machines/[^/]+\.md$#', $norm)
        || (bool)preg_match('#(^|/)CLAUDE\.md$#', $norm);
    if (!$isDoc) {
        exit(0);
    }

    $root = getenv('CLAUDE_PROJECT_DIR') ?: getcwd();
    $script = $root . '/tools/kb_check.py';
    if (!is_file($script)) {
        exit(0);
    }

    $cmd = 'python ' . escapeshellarg($script) . ' --quiet 2>&1';
    $cwd = getcwd();
    chdir($root);
    $out = shell_exec($cmd);
    chdir($cwd);

    $out = trim((string)$out);
    if ($out === '' || !str_contains($out, '!!')) {
        exit(0);                     // clean, or the checker could not run
    }

    // Debounce on the failure set, not on the file.
    $session = (string)($payload['session_id'] ?? 'nosession');
    $stamp = sys_get_temp_dir() . '/kbcheck_' . md5($session . '|' . $out) . '.seen';
    if (file_exists($stamp)) {
        exit(0);
    }
    @touch($stamp);

    echo json_encode([
        'hookSpecificOutput' => [
            'hookEventName'     => 'PostToolUse',
            'additionalContext' =>
                "kb_check found knowledge-base invariant failures after that edit:\n"
                . $out
                . "\n(Run `python tools/kb_check.py` for the full report. These are doc claims "
                . "contradicting the filesystem or the code — fix the doc, or record a deliberate "
                . "historical reference with `allow_missing:` in its frontmatter.)",
        ],
    ]);
} catch (Throwable $e) {
    // Never let this hook break a turn.
}
exit(0);
