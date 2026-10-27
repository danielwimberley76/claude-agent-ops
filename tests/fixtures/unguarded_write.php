<?php
// Fixture for tests/run_smoke.py: a prod script with a bulk write and no WriteScopeGuard.
$pdo = new PDO('sqlite::memory:');
$pdo->exec("UPDATE accounts SET plan = 'basic' WHERE status = 'trial'");
