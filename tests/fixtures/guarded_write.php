<?php
// Fixture for tests/run_smoke.py: the same write, routed through the guard.
require __DIR__ . '/../../lib/WriteScopeGuard.php';
$pdo = new PDO('sqlite::memory:');
(new WriteScopeGuard($pdo))
    ->from('accounts a')
    ->where('a.status = :st', ['st' => 'trial'])
    ->expectAtMost(500)
    ->update("a.plan = 'basic'");
