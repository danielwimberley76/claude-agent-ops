<?php
/**
 * "Does the running app load this file?" — shared by the hooks that care.
 *
 * A change under one of these roots is deployable, so it is board work and its commit needs a
 * `#N:` task id. tools/, tests/, .claude/ and docs/ are not. One list, required by every hook
 * that asks the question, so a nudge and a refusal can never disagree about what counts.
 *
 * ⚙️ Edit HOOK_APP_ROOTS for your repo layout.
 */
declare(strict_types=1);

const HOOK_APP_ROOTS = ['src', 'app', 'lib', 'public', 'cron'];

function hook_is_app_path(string $path): bool
{
    // Leading slash so a repo-relative `tests/Unit/src/…` still hits the /tests/ exclusion below.
    $norm = '/' . ltrim(str_replace('\\', '/', $path), '/');
    $pattern = '~/(' . implode('|', array_map('preg_quote', HOOK_APP_ROOTS)) . ')/~';
    if (!preg_match($pattern, $norm)) {
        return false;
    }
    // A one-off script is app-root-shaped by path but is copy → run → delete, never board work.
    return strpos($norm, '/oneoff/') === false && strpos($norm, '/tests/') === false;
}
