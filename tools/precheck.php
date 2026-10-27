<?php
/**
 * precheck.php — the gate every change passes before it is handed over for deploy.
 *
 *   php tools/precheck.php                 # changed files (git status)
 *   php tools/precheck.php <path> <path>   # exactly these — a manifest's files
 *   php tools/precheck.php --full          # every tracked file — what CI runs
 *   ... --skip=phpunit,docs                # drop steps (lint,js,phpstan,phpunit,docs)
 *
 * Steps, cheapest first:
 *   lint     php -l on each PHP file in scope (parallel)
 *   js       node --check on each non-minified JS file in scope
 *   phpstan  against a baseline — always the WHOLE codebase, because a deleted or renamed symbol
 *            breaks its CALLERS, which are not in the changed set
 *   phpunit  the full suite, Xdebug off
 *   docs     kb_index --check, kb_check, board --check
 *
 * ⭐ A step that cannot run (node, python, phpstan or phpunit missing) reports SKIP, never PASS —
 * a gate that silently waves through what it could not check is worse than no gate.
 *
 * ⚠️ With no paths, "changed" means everything uncommitted in this checkout — which on a shared
 * checkout includes other sessions' work. Pass the manifest's paths explicitly when gating a
 * handover.
 *
 * Exit 0 = every step that ran passed and nothing was skipped; 1 = a failure; 2 = passed with a
 * skip (treated as not-green by CI).
 */

declare(strict_types=1);

// Namespaced so run()/report() cannot collide with same-named globals in other tools/ scripts.
namespace Tools\Precheck;

chdir(dirname(__DIR__));

const ALL_STEPS = ['lint', 'js', 'phpstan', 'phpunit', 'docs'];
const LINT_WORKERS = 8;
const DETAIL_LINES = 40;

$args = array_slice($argv, 1);
$full = in_array('--full', $args, true);
$skip = [];
$paths = [];
foreach ($args as $a) {
    if (str_starts_with($a, '--skip=')) {
        $skip = array_filter(explode(',', substr($a, 7)));
    } elseif (!str_starts_with($a, '--')) {
        $paths[] = str_replace('\\', '/', $a);
    }
}
$unknown = array_diff($skip, ALL_STEPS);
if ($unknown) {
    fwrite(STDERR, 'Unknown step(s) in --skip: ' . implode(',', $unknown) . "\n");
    exit(1);
}

if ($full) {
    $scope = gitLines(['git', 'ls-files']);
    $scopeLabel = 'all tracked files';
} elseif ($paths) {
    $scope = $paths;
    $scopeLabel = count($paths) . ' named path(s)';
} else {
    $scope = changedFiles();
    $scopeLabel = count($scope) . ' changed file(s) in the working tree';
}

$phpFiles = array_values(array_filter($scope, fn($f) => str_ends_with($f, '.php') && is_file($f)));
$jsFiles = array_values(array_filter($scope, fn($f) => str_ends_with($f, '.js') && !str_ends_with($f, '.min.js') && is_file($f)));

echo "precheck — scope: {$scopeLabel} (" . count($phpFiles) . ' PHP, ' . count($jsFiles) . " JS)\n\n";

$results = [];
// Every detail line of every FAILED step — the culprit report at the end mines file paths from it.
$failLines = [];

// ── lint ──────────────────────────────────────────────────────────────────────
if (!in_array('lint', $skip, true)) {
    $t = microtime(true);
    $failures = [];
    foreach (runPool(array_map(fn($f) => [PHP_BINARY, '-l', $f], $phpFiles)) as $i => [$code, $out]) {
        if ($code !== 0) {
            $failures[] = $phpFiles[$i] . ': ' . trim(preg_replace('/\s+/', ' ', $out));
        }
    }
    $results[] = report('lint', $failures ? 'FAIL' : 'PASS', count($phpFiles) . ' file(s)', $failures, $t);
}

// ── js ────────────────────────────────────────────────────────────────────────
if (!in_array('js', $skip, true)) {
    $t = microtime(true);
    if (!$jsFiles) {
        $results[] = report('js', 'PASS', '0 file(s)');
    } elseif (!commandExists('node')) {
        $results[] = report('js', 'SKIP', 'node not found — ' . count($jsFiles) . ' JS file(s) NOT checked');
    } else {
        $failures = [];
        foreach (runPool(array_map(fn($f) => ['node', '--check', $f], $jsFiles)) as $i => [$code, $out]) {
            if ($code !== 0) {
                $failures[] = $jsFiles[$i] . ":\n" . trim($out);
            }
        }
        $results[] = report('js', $failures ? 'FAIL' : 'PASS', count($jsFiles) . ' file(s)', $failures, $t);
    }
}

// ── phpstan ───────────────────────────────────────────────────────────────────
if (!in_array('phpstan', $skip, true)) {
    $t = microtime(true);
    if (!is_file('vendor/bin/phpstan')) {
        $results[] = report('phpstan', 'SKIP', 'vendor/bin/phpstan not installed — static analysis NOT run');
    } else {
        [$code, $out] = run([PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '--memory-limit=2G', '--no-progress', '--error-format=raw']);
        $errors = array_values(array_filter(explode("\n", $out), fn($l) => preg_match('/\.php:\d+:|Result is incomplete/', $l)));
        $errors = array_map(fn($l) => str_replace(getcwd() . DIRECTORY_SEPARATOR, '', trim($l)), $errors);
        if ($code !== 0 && !$errors) {
            $errors = array_slice(explode("\n", trim($out)), -DETAIL_LINES);
        }
        $results[] = report('phpstan', $code === 0 ? 'PASS' : 'FAIL',
            $code === 0 ? 'no new errors vs baseline' : count($errors) . ' new error(s)', $errors, $t);
    }
}

// ── phpunit ───────────────────────────────────────────────────────────────────
if (!in_array('phpunit', $skip, true)) {
    $t = microtime(true);
    if (!is_file('vendor/bin/phpunit')) {
        $results[] = report('phpunit', 'SKIP', 'vendor/bin/phpunit not installed — tests NOT run');
    } else {
        // Xdebug off for the run AND for child PHP processes tests spawn (which -d would not reach):
        // a debug-mode Xdebug alone can triple the suite's runtime. An existing XDEBUG_MODE wins.
        $env = getenv();
        $env['XDEBUG_MODE'] ??= 'off';
        [$code, $out] = run([PHP_BINARY, 'vendor/bin/phpunit'], $env);
        $lines = explode("\n", trim($out));
        $summary = trim((string)end($lines));
        // Show the failure block itself, not just the tail: a long warnings list after the
        // failures pushes the failing test out of a tail.
        $detail = [];
        if ($code !== 0) {
            $first = array_key_first(preg_grep('/^There (was|were) \d+ (error|failure)/', $lines));
            $detail = $first !== null
                ? array_merge(array_slice($lines, $first, DETAIL_LINES), ['…', $summary])
                : array_slice($lines, -DETAIL_LINES);
        }
        $results[] = report('phpunit', $code === 0 ? 'PASS' : 'FAIL', $summary, $detail, $t);
    }
}

// ── docs ──────────────────────────────────────────────────────────────────────
if (!in_array('docs', $skip, true)) {
    $t = microtime(true);
    $python = commandExists('python') ? 'python' : (commandExists('python3') ? 'python3' : null);
    if ($python === null) {
        $results[] = report('docs', 'SKIP', 'python not found — KB and board checks NOT run');
    } else {
        $failures = [];
        $cmds = [[$python, 'tools/kb_index.py', '--check'], [$python, 'tools/kb_check.py'], [$python, 'tools/board.py', '--check']];
        foreach ($cmds as $cmd) {
            [$code, $out] = run($cmd);
            if ($code !== 0) {
                $failures[] = implode(' ', array_slice($cmd, 1)) . ":\n" . implode("\n", array_slice(explode("\n", trim($out)), -15));
            }
        }
        $results[] = report('docs', $failures ? 'FAIL' : 'PASS', 'kb_index, kb_check, board', $failures, $t);
    }
}

// ── verdict ───────────────────────────────────────────────────────────────────
$failed = in_array('FAIL', $results, true);
$skipped = in_array('SKIP', $results, true);
echo "\n";
if ($failed) {
    culpritReport($failLines);
    echo "PRECHECK FAILED — do not hand over a manifest.\n";
    exit(1);
}
if ($skipped) {
    echo "PRECHECK PASSED WITH SKIPS — the skipped steps were not checked.\n";
    exit(2);
}
echo "PRECHECK PASSED\n";
exit(0);

// ─────────────────────────────────────────────────────────────────────────────

function report(string $step, string $status, string $detail, array $lines = [], ?float $started = null): string
{
    if ($status === 'FAIL') {
        array_push($GLOBALS['failLines'], ...$lines);
    }
    $took = $started === null ? '' : sprintf(' (%.1fs)', microtime(true) - $started);
    printf("  [%s] %-8s %s%s\n", $status, $step, $detail, $took);
    $shown = array_slice($lines, 0, DETAIL_LINES);
    foreach ($shown as $l) {
        echo '        ' . str_replace("\n", "\n        ", $l) . "\n";
    }
    if (count($lines) > count($shown)) {
        echo '        … ' . (count($lines) - count($shown)) . " more\n";
    }
    return $status;
}

/**
 * Who to ask about a red precheck: for each repo file named in a failure, the last commit that
 * touched it, and whether it has uncommitted changes in this checkout — on a shared tree that is
 * often ANOTHER session's work in progress, not a committed break.
 *
 * @param string[] $lines
 */
function culpritReport(array $lines): void
{
    $root = str_replace('\\', '/', getcwd()) . '/';
    $files = [];
    foreach ($lines as $l) {
        if (!preg_match_all('~((?:[A-Za-z]:)?[\w./\\\\-]+\.php)(?=:|\s|$)~', (string)$l, $m)) {
            continue;
        }
        foreach ($m[1] as $p) {
            $p = str_replace('\\', '/', $p);
            if (stripos($p, $root) === 0) {
                $p = substr($p, strlen($root));
            }
            if (is_file($p) && !str_starts_with($p, 'vendor/')) {
                $files[$p] = true;
            }
        }
    }
    if (!$files) {
        return;
    }

    echo "\n  Who last touched the failing files (message them, or fix forward under your own id):\n";
    foreach (array_slice(array_keys($files), 0, 12) as $f) {
        [, $last] = run(['git', 'log', '-1', '--format=%h  %ad  %an  %s', '--date=format:%Y-%m-%d %H:%M', '--', $f]);
        [, $dirty] = run(['git', 'status', '--porcelain', '--', $f]);
        $last = trim($last) !== '' ? trim($last) : '(no commit yet)';
        echo "    {$f}\n        last commit: {$last}\n";
        if (trim($dirty) !== '') {
            echo "        ⚠️ has UNCOMMITTED changes in this checkout — on a shared tree that may be another session's work in progress\n";
        }
    }
    echo "\n";
}

/**
 * @param array<string,string>|null $env the child's whole environment; null inherits this one
 * @return array{int,string} exit code and combined stdout+stderr
 */
function run(array $cmd, ?array $env = null): array
{
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env);
    if (!is_resource($proc)) {
        return [127, 'could not start: ' . implode(' ', $cmd)];
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($proc), (string)$out];
}

/**
 * Run many short commands, LINT_WORKERS at a time — sequential php -l over ~1,000 files is the
 * difference between a gate people run and one they skip.
 * @return array<int, array{int,string}> keyed by the command's index
 */
function runPool(array $cmds): array
{
    $results = [];
    $running = [];
    $next = 0;
    while ($next < count($cmds) || $running) {
        while ($next < count($cmds) && count($running) < LINT_WORKERS) {
            $proc = proc_open($cmds[$next], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
            $running[$next] = [$proc, $pipes[1]];
            $next++;
        }
        foreach ($running as $i => [$proc, $pipe]) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $out = stream_get_contents($pipe);
                fclose($pipe);
                proc_close($proc);
                $results[$i] = [$status['exitcode'], (string)$out];
                unset($running[$i]);
            }
        }
        if ($running) {
            usleep(5000);
        }
    }
    ksort($results);
    return $results;
}

function commandExists(string $cmd): bool
{
    [$code] = run(PHP_OS_FAMILY === 'Windows' ? ['where', $cmd] : ['sh', '-c', 'command -v ' . escapeshellarg($cmd)]);
    return $code === 0;
}

/** @return string[] */
function gitLines(array $cmd): array
{
    [, $out] = run($cmd);
    return array_values(array_filter(array_map('trim', explode("\n", $out))));
}

/** Modified, added, renamed and untracked files — deletions have nothing left to check. */
function changedFiles(): array
{
    $files = [];
    foreach (gitLines(['git', 'status', '--porcelain', '--untracked-files=all']) as $line) {
        $xy = substr($line, 0, 2);
        $path = substr($line, 3);
        if (str_contains($xy, 'D')) {
            continue;
        }
        if (str_contains($path, ' -> ')) {
            $path = explode(' -> ', $path)[1];
        }
        $files[] = trim($path, '"');
    }
    return $files;
}
