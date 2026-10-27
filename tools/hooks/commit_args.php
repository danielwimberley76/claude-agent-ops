<?php
/**
 * Shared parsing of a `git commit …` command line for the Bash/PowerShell hooks.
 *
 * Kept in one file so every hook that reads a commit's message and pathspec reads them the SAME
 * way. Two copies of a quote-aware tokenizer drift; this is the one that has been exercised.
 */
declare(strict_types=1);

if (!defined('CMD_POS')) {
    /** `git` at a command position — start of line, or after && || ; | or a newline, behind env assignments. */
    define('CMD_POS', '(?:^|&&|\|\||;|\||\n)\s*(?:[A-Za-z_][A-Za-z0-9_]*=\S*\s+)*');
}

/**
 * Quote-aware argument tokens of the `git commit` in $cmd, stopping at the first shell separator.
 *
 * @return string[] [] when there is no `git commit`
 */
function commit_tokens(string $cmd, bool $isPs): array
{
    $toks = [];
    if (!preg_match('/' . CMD_POS . 'git\b(?:\s+-\S+(?:\s+\S+)?)*\s+commit\b(.*)$/s', $cmd, $rm)) {
        return $toks;
    }
    $tokRe = $isPs
        ? '/@\'\r?\n(.*?)\r?\n\'@|@"\r?\n(.*?)\r?\n"@|"((?:[^"`]|`.)*)"|\'((?:[^\']|\'\')*)\'|(\S+)/s'
        : '/()()"((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\'|(\S+)/';
    preg_match_all($tokRe, $rm[1], $all, PREG_SET_ORDER);
    foreach ($all as $t) {
        $bare = $t[5] ?? '';
        if (in_array($bare, ['&&', '||', ';', '|'], true)) {
            break;
        }
        if ($bare !== '') {
            $toks[] = $bare;
        } elseif (($t[1] ?? '') !== '') {
            $toks[] = $t[1];                                  // @'…'@ — literal
        } elseif (($t[2] ?? '') !== '') {
            $toks[] = preg_replace('/`(.)/s', '$1', $t[2]);  // @"…"@
        } elseif (($t[4] ?? '') !== '') {
            $toks[] = $isPs ? str_replace("''", "'", $t[4]) : $t[4];
        } else {
            $toks[] = $isPs ? preg_replace('/`(.)/s', '$1', $t[3] ?? '') : stripcslashes($t[3] ?? '');
        }
    }
    return $toks;
}

/** Git Bash spells C:\x as /c/x; PHP on Windows can only open the former. */
function commit_win_path(string $p): string
{
    return DIRECTORY_SEPARATOR === '\\' && preg_match('~^/([a-zA-Z])/(.*)$~', $p, $m)
        ? strtoupper($m[1]) . ':/' . $m[2] : $p;
}

/** The directory the commit runs in: the tool's cwd, adjusted for `git -C <dir>`. */
function commit_cwd(string $cmd, string $toolCwd): string
{
    $cwd = $toolCwd;
    if (preg_match('/\bgit\s+-C\s+("[^"]+"|\'[^\']+\'|\S+)/', $cmd, $cm)) {
        $c = trim($cm[1], '"\'');
        $cwd = preg_match('~^[A-Za-z]:|^/[a-z]/~', $c) || $c[0] === '/' ? $c : rtrim($cwd, '/\\') . '/' . $c;
    }
    return commit_win_path($cwd);
}

/**
 * Split commit tokens into the message (subject source) and the pathspec after `--`.
 *
 * @param string[] $toks
 * @return array{subject: ?string, paths: string[]}
 */
function commit_message_and_paths(array $toks, string $cwd): array
{
    $subject = null;
    $paths = [];
    $afterDashes = false;
    for ($i = 0, $n = count($toks); $i < $n; $i++) {
        $t = $toks[$i];
        if ($afterDashes) {
            $paths[] = $t;
            continue;
        }
        if ($t === '--') {
            $afterDashes = true;
            continue;
        }
        $msg = $file = null;
        if ($t === '-m' || $t === '--message') {
            $msg = $toks[++$i] ?? null;
        } elseif (strncmp($t, '--message=', 10) === 0) {
            $msg = substr($t, 10);
        } elseif ($t === '-F' || $t === '--file') {
            $file = $toks[++$i] ?? null;
        } elseif (strncmp($t, '--file=', 7) === 0) {
            $file = substr($t, 7);
        }
        if ($msg !== null && $subject === null) {
            $subject = $msg;
        }
        if ($file !== null && $file !== '-' && $subject === null) {
            $f = commit_win_path($file);
            if (!preg_match('~^[A-Za-z]:|^/~', $f)) {
                $f = rtrim($cwd, '/\\') . '/' . $f;
            }
            $body = is_file($f) ? @file_get_contents($f) : false;
            if ($body !== false) {
                $subject = $body;
            }
        }
    }
    return ['subject' => $subject, 'paths' => $paths];
}
