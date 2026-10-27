<?php
/**
 * SSH guard (PreToolUse, Bash + PowerShell): the only SSH an agent runs is `ssh <PEEK_HOST> …`.
 *
 * The workstation's ~/.ssh holds the OWNER's full-power prod keys (a shell as the deploy account,
 * a sudo-capable admin login). The peek key is bound on the server to a forced command that can
 * only run a read-only inspection script. So this refuses:
 *   - ssh to any host but PEEK_HOST, or with any option but -o/-q/-T/-v (so never -i, -L, -R, -D, -A)
 *   - scp, sftp, plink, and rsync over ssh, outright
 *   - any command naming the owner's key files, GIT_SSH(_COMMAND) or core.sshCommand
 * ssh-keygen / ssh-add / ~/.ssh paths are not `ssh` and pass. The owner's own `!` shell commands
 * never reach a hook.
 *
 * The scan is on the RAW command, quotes included: `bash -c "ssh box"` must not slip through. The
 * price is a rare false refusal (`grep "ssh "`), which the reason explains.
 *
 * ⚙️ Set PEEK_HOST to your forced-command host alias and OWNER_KEY_RE to your key file names.
 *
 * Rationale: .claude/decisions.md#agent-ssh-is-a-forced-command
 */
declare(strict_types=1);

const PEEK_HOST = 'prod-peek';
const OWNER_KEY_RE = '/deploy_key|admin_key|\.pem\b|GIT_SSH|sshCommand/i';

$data = json_decode((string)stream_get_contents(STDIN), true) ?: [];
$cmd = (string)($data['tool_input']['command'] ?? '');

$why = sshGuardReason($cmd);
if ($why === null) {
    exit(0);
}
echo json_encode([
    'hookSpecificOutput' => [
        'hookEventName' => 'PreToolUse',
        'permissionDecision' => 'deny',
        'permissionDecisionReason' => "SSH guard: {$why} Agents reach prod only through `ssh " . PEEK_HOST . " <command>`; "
            . 'the other keys in ~/.ssh are the owner\'s. Don\'t retry another way. '
            . 'If the box needs something peek can\'t do, hand the owner the command.',
    ],
], JSON_UNESCAPED_SLASHES);
exit(0);

function sshGuardReason(string $cmd): ?string
{
    if (preg_match(OWNER_KEY_RE, $cmd, $m)) {
        return "the command names `{$m[0]}`.";
    }
    if (preg_match('/(?<![\w.-])(scp|sftp|plink|pscp)(?:\.exe)?(?=[\s"\'`;|&)]|$)/i', $cmd, $m)) {
        return "`{$m[1]}` is never allowed.";
    }
    if (preg_match('/(?<![\w.-])rsync\b[^\n;|&]*(?:-e|--rsh)/i', $cmd)) {
        return 'rsync over ssh is never allowed.';
    }
    // Bare `ssh` is matched lowercase only, so prose ("every SSH but …" in a commit message) passes;
    // with `.exe` any case is caught, since Windows runs SSH.EXE as readily as ssh.exe.
    if (!preg_match_all('/(?<![\w.-])(?:ssh|(?i:ssh\.exe))(?=[\s"\'`;|&)]|$)/', $cmd, $all, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    foreach ($all[0] as [$hit, $at]) {
        // `where ssh`, `Get-Command ssh -All`: a lookup names the binary without running it.
        if (preg_match('/(?:^|[\s;|&(])(?:where(?:\.exe)?|which|type|Get-Command|gcm|command\s+-v)\s+$/i', substr($cmd, 0, $at))) {
            continue;
        }
        $rest = preg_split('/\s+/', ltrim(substr($cmd, $at + strlen($hit))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $host = null;
        for ($i = 0; $i < count($rest); $i++) {
            // A host written flush against the next command separator ("prod-peek;") is still that host.
            $t = rtrim(trim($rest[$i], "\"'`"), ';|&)');
            if ($t === '-o' || preg_match('/^-o(.+)$/', $t, $om)) {
                // Only options that can't change where it goes or which key it offers
                // (ProxyCommand, HostName, IdentityFile, LocalCommand all could).
                $o = $t === '-o' ? trim($rest[++$i] ?? '', "\"'`") : $om[1];
                if (!preg_match('/^(BatchMode|ConnectTimeout|ServerAliveInterval|ServerAliveCountMax|LogLevel)[= ]?/i', $o)) {
                    return "ssh option `-o {$o}` is not allowed.";
                }
            } elseif (preg_match('/^-[qTv]+$/', $t)) {
                // quiet, no tty, verbose: harmless
            } elseif (str_starts_with($t, '-')) {
                return "ssh option `{$t}` is not allowed.";
            } else {
                $host = $t;
                break;
            }
        }
        if ($host !== PEEK_HOST) {
            return $host === null ? 'a bare ssh with no host.' : "ssh to `{$host}` is not allowed.";
        }
    }
    return null;
}
