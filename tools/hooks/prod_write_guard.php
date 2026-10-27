<?php
declare(strict_types=1);

/**
 * Bash/PowerShell-tool guard: refuses to run a script against PROD when it contains a bulk write
 * that does not go through WriteScopeGuard (lib/WriteScopeGuard.php).
 *
 * Why a hook and not just a convention: both prod data-loss incidents this was built after were
 * a wrong WHERE in a script that never asked itself how many rows it would touch. A helper class
 * only protects the people who already remember to be careful — which is not the failure mode.
 * This makes the careless path the one that stops.
 *
 * Scope is deliberately narrow, because a guard that fires on reads would be turned off:
 *   * only a segment of the command that actually INVOKES php (see "The trigger surface" below)
 *   * only when that invocation names prod (a `prod` argument, --env=prod, or the prod creds file)
 *   * only when the script file it runs contains UPDATE / DELETE / REPLACE / TRUNCATE / DROP
 *   * never when the file already references WriteScopeGuard
 *
 * Override, for a write that genuinely cannot use the guard (DDL, a single-row write by primary
 * key): prefix the command with PRODWRITE_OK=1 and say why in the script's header.
 *
 * ⭐ THE TRIGGER SURFACE IS A PARSE, NOT A SEARCH — and that is the whole point of this file's
 * length. The first version tested `prod` and `*.php` against the raw command STRING, quoted text
 * included, so a `git commit` whose MESSAGE said "Deployed to prod", naming a model file that
 * happened to contain the word UPDATE, was refused although it executed nothing. That denied four
 * ordinary commits in one afternoon, and each had to be forced with PRODWRITE_OK=1 — the
 * corrosive failure for a safety hook, because it trains every session to reach for the override
 * reflexively. So instead:
 *
 *   1. heredoc bodies are removed, then the command is LEXED (quote-aware) into segments split
 *      on ; | & and newlines — a quoted span never contributes a token boundary or a keyword;
 *   2. a segment is examined ONLY if its command word is a php binary. `git`, `sed`, `grep`,
 *      `cat` and friends are skipped whatever their arguments or messages say;
 *   3. `bash -c` / `sh -c` / `powershell -Command` recurse into their payload, so wrapping is not
 *      a bypass;
 *   4. `-r` / `-d` VALUES are not treated as arguments — an inline str_replace over a doc that
 *      happens to contain "prod" and a `.php` filename is not a prod write. (Inline code that
 *      names the prod creds file AND writes IS caught.)
 *
 * ⚙️ PROD_CRED_FILE and the prod-argument shapes in $examinePhp are the parts to adapt.
 */

const PROD_CRED_FILE = 'prod_db.json';

$data = json_decode((string) stream_get_contents(STDIN), true) ?: [];
$cmd = (string) ($data['tool_input']['command'] ?? '');

// PowerShell quotes differently: `\` is a literal (so "C:\tools\php\php.exe" must stay a path —
// read with Bash rules it lexes to C:toolsphpphp.exe and fails OPEN), and the escape is a backtick.
$ps = ($data['tool_name'] ?? '') === 'PowerShell';

if (trim($cmd) === '') {
    exit(0);
}

// Deliberate override, checked first so it costs nothing else.
if (strpos($cmd, 'PRODWRITE_OK=1') !== false) {
    exit(0);
}

$project = rtrim(getenv('CLAUDE_PROJECT_DIR') ?: '.', "/\\");

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

/**
 * Remove heredoc bodies before lexing. A heredoc body is arbitrary text — SQL, a commit message,
 * a doc paragraph — and it is not an argument to anything. Left in, its stray quotes would
 * unbalance the lexer, which fails OPEN (the rest of the command is swallowed into a quoted span
 * and no php invocation is ever seen).
 */
$stripHeredocs = static function (string $s, bool $ps): string {
    if ($ps) {
        // PowerShell here-strings: @' or @" ends its line, and the body runs to a line starting '@ / "@.
        $s = (string) preg_replace('/@([\'"])[ \t]*\r?\n.*?\r?\n\1@/s', ' HERESTRING ', $s);
        if (preg_match('/@[\'"][ \t]*\r?\n/', $s, $om, PREG_OFFSET_CAPTURE)) {
            $s = substr($s, 0, $om[0][1]);
        }
        return $s;
    }
    $s = (string) preg_replace(
        '/<<-?\s*([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\1(?:.*?)^[ \t]*\2[ \t]*$/ms',
        ' <<HEREDOC ',
        $s
    );
    // An opener with no terminator in the captured command: truncate there rather than lex a body.
    if (preg_match('/<<-?\s*[\'"]?[A-Za-z_]/', $s, $om, PREG_OFFSET_CAPTURE)) {
        $s = substr($s, 0, $om[0][1]);
    }
    return $s;
};

/**
 * Quote-aware lexer. Returns a list of segments; each segment is a list of tokens:
 *   ['v' => <text with quotes removed>, 'q' => <true if any of it was quoted>]
 * Segments break on ; && || | and newline — never inside quotes.
 */
$lex = static function (string $s, bool $ps): array {
    $segments = [];
    $tokens = [];
    $cur = '';
    $curQuoted = false;
    $has = false; // $cur holds something, even if it lexed to the empty string ('' as an argument)

    $endToken = static function () use (&$cur, &$curQuoted, &$has, &$tokens): void {
        if ($has) {
            $tokens[] = ['v' => $cur, 'q' => $curQuoted];
        }
        $cur = '';
        $curQuoted = false;
        $has = false;
    };
    $endSegment = static function () use (&$tokens, &$segments, $endToken): void {
        $endToken();
        if ($tokens !== []) {
            $segments[] = $tokens;
        }
        $tokens = [];
    };

    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];

        if ($ch === "'") {
            $has = true;
            $curQuoted = true;
            $j = strpos($s, "'", $i + 1);
            if ($j === false) {
                $cur .= substr($s, $i + 1);
                break;
            }
            $cur .= substr($s, $i + 1, $j - $i - 1);
            $i = $j;
            continue;
        }

        if ($ch === '"') {
            $has = true;
            $curQuoted = true;
            for ($j = $i + 1; $j < $len; $j++) {
                if ($s[$j] === ($ps ? '`' : '\\') && $j + 1 < $len) {
                    $cur .= $s[$j + 1];
                    $j++;
                    continue;
                }
                if ($s[$j] === '"') {
                    break;
                }
                $cur .= $s[$j];
            }
            $i = $j;
            continue;
        }

        if ($ps && $ch === '`' && $i + 1 < $len) {
            // PowerShell escape: backtick-newline continues the line; otherwise the next char is literal.
            if ($s[$i + 1] !== "\n") {
                $has = true;
                $cur .= $s[$i + 1];
            }
            $i++;
            continue;
        }

        if (!$ps && $ch === '\\' && $i + 1 < $len) {
            // A line continuation joins the command; anything else is a literal next char.
            if ($s[$i + 1] === "\n") {
                $i++;
                continue;
            }
            $has = true;
            // Bash would drop the backslash; we KEEP it before a word character so that a Windows
            // path written unquoted still lexes to a recognisable binary name — else it fails open.
            if (preg_match('/[A-Za-z0-9_.]/', $s[$i + 1])) {
                $cur .= $ch;
            }
            $cur .= $s[$i + 1];
            $i++;
            continue;
        }

        if ($ch === ' ' || $ch === "\t") {
            $endToken();
            continue;
        }

        if ($ch === "\n" || $ch === "\r" || $ch === ';' || $ch === '|' || $ch === '&') {
            $endSegment();
            continue;
        }

        // Subshell / grouping punctuation is not part of a command word: `(cd x && php y.php prod)`
        if ($ch === '(' || $ch === ')' || $ch === '{' || $ch === '}') {
            $endSegment();
            continue;
        }

        $has = true;
        $cur .= $ch;
    }
    $endSegment();

    return $segments;
};

/** basename of a path written in any of the three forms a Windows + Git Bash box produces. */
$base = static function (string $p): string {
    $p = str_replace('\\', '/', $p);
    $pos = strrpos($p, '/');
    return $pos === false ? $p : substr($p, $pos + 1);
};

/**
 * Resolve a path as written in a command to something PHP can open. Three forms occur:
 *   tools/oneoff/x.php    — relative, resolved against CLAUDE_PROJECT_DIR
 *   C:/work/repo/...      — native absolute
 *   /c/work/repo/...      — Git Bash's absolute form, which PHP on Windows does NOT understand
 * Missing that third form made the guard silently allow every write it was meant to stop — it
 * read no file, found no UPDATE, and exited 0. Caught by the hook's own tests.
 */
$resolve = static function (string $p) use ($project): ?string {
    $p = str_replace('\\', '/', $p);
    $candidates = [$p];
    if (preg_match('#^/([A-Za-z])/(.*)$#', $p, $dm)) {
        $candidates[] = $dm[1] . ':/' . $dm[2];
    }
    $candidates[] = $project . '/' . ltrim($p, '/');
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    return null;
};

/**
 * Strip comments while PRESERVING every byte offset, so a match can be reported with its real line
 * number. Two different false readings depend on it:
 *   * a docblock that merely MENTIONS "UPDATE accounts" would deny a read-only report;
 *   * a docblock that merely MENTIONS WriteScopeGuard would mark an UNGUARDED write as guarded —
 *     the more dangerous direction, since it fails open.
 */
$stripComments = static function (string $src): string {
    $blank = static fn(array $mm): string => (string) preg_replace('/[^\n]/', ' ', $mm[0]);
    return (string) preg_replace_callback(
        ['#/\*.*?\*/#s', '#(^|\s)//.*$#m', '#(^|\s)\#.*$#m'],
        $blank,
        $src
    );
};

$WRITE_VERBS = '/\b(UPDATE|DELETE\s+FROM|REPLACE\s+INTO|TRUNCATE|DROP\s+(TABLE|COLUMN))\b/i';

/**
 * The refusal text. Names the file AND every line that matched, so an override decision can be
 * informed rather than reflexive. ALL the lines, not the first: a script's first hit is often prose
 * inside an echo ("DRY RUN — would: UPDATE 12 rows"), and anchoring on that reads as a false
 * positive even when the real writes are further down.
 */
$refusal = static function (string $rel, string $verb, string $lines): string {
    $where = $lines === '' ? $rel : "{$rel}:{$lines}";
    return "REFUSED: {$where} contains a bulk write ({$verb}) and is being run against PROD "
        . "without WriteScopeGuard.\n\n"
        . "Both prod data-loss incidents behind this hook were a wrong WHERE in exactly this shape — "
        . "one intended a few hundred rows and matched hundreds of thousands. The guard counts the "
        . "write's own predicate first and refuses on a mismatch:\n\n"
        . "  (new WriteScopeGuard(\$pdo))\n"
        . "      ->from('accounts a')\n"
        . "      ->where('a.status = :st AND a.created_at >= :d', [...])\n"
        . "      ->expectAtMost(500)\n"
        . "      ->rollbackFile('tools/oneoff/rollback_<date>.sql', 'accounts', 'id', ['plan'])\n"
        . "      ->update('a.plan = :plan', ['plan' => \$plan]);\n\n"
        . "Read {$where} before overriding — the guard cannot tell a bulk write from "
        . "WHERE id = :id. If this write genuinely cannot use the guard (DDL, or a single row by "
        . "primary key), prefix the command with PRODWRITE_OK=1 and record why in the script's header.";
};

/**
 * Examine one php invocation. Returns null to allow, or the refusal text.
 * $tokens[0] is the php binary. -r/-d values are CODE, not arguments.
 */
$examinePhp = static function (array $tokens) use ($resolve, $stripComments, $WRITE_VERBS, $refusal): ?string {
    $args = [];
    $inlineCode = '';

    $n = count($tokens);
    for ($i = 1; $i < $n; $i++) {
        $t = $tokens[$i]['v'];
        if ($t === '-r' || $t === '-d') {
            if ($i + 1 < $n) {
                $inlineCode .= ' ' . $tokens[$i + 1]['v'];
                $i++;
            }
            continue;
        }
        if (strlen($t) > 2 && ($t[0] === '-') && ($t[1] === 'r' || $t[1] === 'd')) {
            $inlineCode .= ' ' . substr($t, 2); // -rCODE / -dkey=value
            continue;
        }
        $args[] = $t;
    }

    // Inline code that opens the prod credentials AND writes: the file scan cannot see this.
    if (strpos($inlineCode, PROD_CRED_FILE) !== false && preg_match($WRITE_VERBS, $inlineCode, $ihit)) {
        return $refusal('php -r (inline code)', strtoupper((string) preg_replace('/\s+/', ' ', $ihit[0])), '');
    }

    // Does this invocation target prod? `script.php prod`, --env=prod, -e prod, or the creds file.
    $targetsProd = false;
    foreach ($args as $k => $a) {
        if ($a === 'prod' || $a === '--env=prod' || $a === 'env=prod'
            || strpos($a, PROD_CRED_FILE) !== false
            || (($args[$k - 1] ?? '') === '-e' && $a === 'prod')) {
            $targetsProd = true;
            break;
        }
    }
    if (!$targetsProd) {
        return null;
    }

    foreach ($args as $a) {
        if (substr($a, -4) !== '.php') {
            continue;
        }
        $path = $resolve($a);
        if ($path === null) {
            continue;
        }
        $src = (string) @file_get_contents($path);
        if ($src === '') {
            continue;
        }

        $code = $stripComments($src);

        if (stripos($code, 'WriteScopeGuard') !== false) {
            continue; // actually guarded in code
        }
        if (!preg_match_all($WRITE_VERBS, $code, $hits, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            continue;
        }

        $verbs = [];
        $lines = [];
        foreach ($hits as $h) {
            $verbs[strtoupper((string) preg_replace('/\s+/', ' ', $h[0][0]))] = true;
            $lines[] = substr_count(substr($code, 0, $h[0][1]), "\n") + 1;
        }
        $lines = array_values(array_unique($lines));
        $shown = array_slice($lines, 0, 6);
        $more = count($lines) - count($shown);

        return $refusal(
            $a,
            implode(', ', array_keys($verbs)),
            implode(',', $shown) . ($more > 0 ? " (+{$more} more)" : '')
        );
    }

    return null;
};

/**
 * Walk every segment of a command, recursing through shell-wrapper payloads so that wrapping an
 * invocation in a shell string is not a bypass. Depth-limited for safety.
 */
$walk = static function (string $command, int $depth, bool $ps) use (&$walk, $lex, $stripHeredocs, $base, $examinePhp): ?string {
    if ($depth > 3) {
        return null;
    }

    foreach ($lex($stripHeredocs($command, $ps), $ps) as $tokens) {
        // Drop leading VAR=value assignments and common prefixes that precede the real command.
        while ($tokens !== []) {
            $t = $tokens[0]['v'];
            if (!$tokens[0]['q'] && preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $t)) {
                array_shift($tokens);
                continue;
            }
            $b = $base($t);
            if (in_array($b, ['env', 'nohup', 'time', 'exec', 'command', 'xargs'], true)) {
                array_shift($tokens);
                continue;
            }
            break;
        }
        if ($tokens === []) {
            continue;
        }

        $b = $base($tokens[0]['v']);

        // A shell wrapper: recurse into the -c payload, and only that.
        if (preg_match('/^(ba|z|k|da)?sh(\.exe)?$/', $b)) {
            $n = count($tokens);
            for ($i = 1; $i < $n; $i++) {
                if (preg_match('/^-[a-z]*c$/', $tokens[$i]['v']) && $i + 1 < $n) {
                    $r = $walk($tokens[$i + 1]['v'], $depth + 1, false);
                    if ($r !== null) {
                        return $r;
                    }
                    break;
                }
            }
            continue;
        }

        // powershell -Command "…" / pwsh -c "…" / cmd /c "…": recurse into the payload with
        // PowerShell lexing rules — both treat a backslash as a literal.
        if (preg_match('/^(powershell|pwsh|cmd)(\.exe)?$/i', $b)) {
            $n = count($tokens);
            for ($i = 1; $i < $n; $i++) {
                if (preg_match('#^(-c|-command|/c|/k)$#i', $tokens[$i]['v']) && $i + 1 < $n) {
                    $payload = implode(' ', array_column(array_slice($tokens, $i + 1), 'v'));
                    $r = $walk($payload, $depth + 1, true);
                    if ($r !== null) {
                        return $r;
                    }
                    break;
                }
            }
            continue;
        }

        // The whole point: only an actual php invocation is examined.
        if (!preg_match('/^php[0-9.]*(\.exe)?$/', $b)) {
            continue;
        }

        $r = $examinePhp($tokens);
        if ($r !== null) {
            return $r;
        }
    }

    return null;
};

$reason = $walk($cmd, 0, $ps);
if ($reason !== null) {
    $deny($reason);
}

exit(0);
