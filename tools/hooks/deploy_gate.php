<?php
/**
 * Deploy gate: an agent may push or start/tag a pipeline only after the OWNER typed "deploy"
 * in that same session.
 *
 *   prompt (UserPromptSubmit) — a prompt that is just "deploy", "deploy #N" or "deploy #N #M"
 *                               clears THIS session for DEPLOY_TTL seconds.
 *   pre    (PreToolUse)       — refuses `git push` and `tools/pipeline.php run|tag` unless this
 *                               session is cleared. `--dry-run` passes: it ships nothing.
 *
 * Why a hook and not permission rules: `ask` rules did not prompt in auto mode, and a `deny`
 * rule can't tell that the owner said "deploy", so it blocked real deploys too. A PreToolUse
 * deny applies in every permission mode.
 *
 * ⛔ The trigger match is strict on purpose. Other sessions can deliver text into a session
 *    (agent-to-agent messages), so a whole-prompt match on the bare word can't be set off by
 *    one. Don't loosen it to "contains deploy".
 *
 * Rationale: .claude/decisions.md#deploy-gate-hook
 */
declare(strict_types=1);

const DEPLOY_TTL = 3600;

$mode = $argv[1] ?? 'pre';
$data = json_decode((string)stream_get_contents(STDIN), true) ?: [];

$session = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($data['session_id'] ?? '')) ?: 'nosession';
$project = rtrim((string)(getenv('CLAUDE_PROJECT_DIR') ?: '.'), "/\\");
$stateDir = (string)(getenv('CC_DEPLOY_GATE_DIR') ?: $project . '/.git');
$stateFile = $stateDir . '/cc_deploy_ok_' . $session;

if ($mode === 'prompt') {
    $prompt = trim((string)($data['prompt'] ?? ''));
    if (!preg_match('/^deploy(?:[\s,]+(?:and\s+)?#?\d+)*[.!]?$/i', $prompt)) {
        exit(0);
    }
    if (is_dir($stateDir)) {
        @file_put_contents($stateFile, (string)time());
    }
    echo json_encode([
        'hookSpecificOutput' => [
            'hookEventName' => 'UserPromptSubmit',
            'additionalContext' => 'Deploy gate: the owner said "deploy". This session may run git push and '
                . 'tools/pipeline.php run|tag for the next ' . (DEPLOY_TTL / 60) . ' minutes.',
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit(0);
}

$cmd = (string)($data['tool_input']['command'] ?? '');
$isPs = ($data['tool_name'] ?? '') === 'PowerShell';
$quoteRe = $isPs
    ? '/"(?:[^"`]|`.)*"|\'(?:[^\']|\'\')*\'/s'
    : '/"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'/';
// Quoted spans blanked, so `grep "git push"` is not a push (see git_commit_guard.php).
$scan = preg_replace($quoteRe, 'Q', $cmd) ?? $cmd;

$cmdPos = '(?:^|&&|\|\||;|\||\n)\s*(?:&\s*)?(?:[A-Za-z_][A-Za-z0-9_]*=\S*\s+)*';
$isPush = (bool)preg_match('/' . $cmdPos . 'git\b(?:\s+-\S+(?:\s+\S+)?)*\s+push\b/', $scan);
// Raw $cmd here: the script path may itself be quoted ("tools/pipeline.php" run).
$isPipeline = (bool)preg_match('/pipeline\.php["\']?\s+(?:run|tag)\b/', $cmd);

if (!$isPush && !$isPipeline) {
    exit(0);
}
if (preg_match('/(?:^|\s)--dry-run\b/', $scan)) {
    exit(0);
}

$at = is_file($stateFile) ? (int)@file_get_contents($stateFile) : 0;
if ($at > 0 && time() - $at <= DEPLOY_TTL) {
    exit(0);
}

echo json_encode([
    'hookSpecificOutput' => [
        'hookEventName' => 'PreToolUse',
        'permissionDecision' => 'deny',
        'permissionDecisionReason' => 'Deploy gate: push and pipeline run/tag need the owner to type "deploy" '
            . '(or "deploy #N") in THIS session first; it then holds for ' . (DEPLOY_TTL / 60) . ' minutes. '
            . 'Ask in one line ("Say deploy to push and tag #N."), then stop. Don\'t retry another way. '
            . '--dry-run is always allowed.',
    ],
], JSON_UNESCAPED_SLASHES);
exit(0);
