<?php
declare(strict_types=1);

/**
 * WriteScopeGuard — a bulk UPDATE/DELETE that counts its own predicate before it writes.
 *
 * The failure it exists for: a one-off script with a wrong WHERE. Nobody asked "how many rows will
 * this touch?", so a write meant for a few hundred rows hit hundreds of thousands. The guard makes
 * the question mandatory:
 *
 *   (new WriteScopeGuard($pdo))
 *       ->from('accounts a')
 *       ->where('a.status = :st AND a.created_at >= :d', ['st' => 'trial', 'd' => '2026-01-01'])
 *       ->expectAtMost(500)
 *       ->rollbackFile('tools/oneoff/rollback_2026-01-31.sql', 'accounts', 'id', ['plan'])
 *       ->update('a.plan = :plan', ['plan' => 'basic']);
 *
 * 1. COUNT(*) with the exact FROM + WHERE the write will use — not a hand-written lookalike.
 * 2. Refuse (throw) if the count exceeds expectAtMost(), or is zero.
 * 3. Optionally write a rollback file: one UPDATE per row restoring the named columns.
 * 4. Run the write in a transaction, and roll back if the affected count differs from step 1.
 *
 * tools/hooks/prod_write_guard.php refuses to run a prod script that writes without this class.
 */
final class WriteScopeGuard
{
    private string $from = '';
    private string $where = '';
    private array $params = [];
    private ?int $max = null;
    private ?array $rollback = null;

    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function from(string $from): self
    {
        $this->from = $from;
        return $this;
    }

    public function where(string $where, array $params = []): self
    {
        $this->where = $where;
        $this->params = $params;
        return $this;
    }

    public function expectAtMost(int $max): self
    {
        $this->max = $max;
        return $this;
    }

    /** @param string[] $columns columns to restore; $table/$key name the row identity */
    public function rollbackFile(string $path, string $table, string $key, array $columns): self
    {
        $this->rollback = compact('path', 'table', 'key', 'columns');
        return $this;
    }

    /** @return int rows updated */
    public function update(string $set, array $setParams = []): int
    {
        $n = $this->preflight();
        $alias = $this->alias();
        $sql = "UPDATE {$this->from} SET {$set} WHERE {$this->where}";
        return $this->run($sql, $setParams + $this->params, $n, $alias);
    }

    /** @return int rows deleted */
    public function delete(): int
    {
        $n = $this->preflight();
        $alias = $this->alias();
        $sql = "DELETE {$alias} FROM {$this->from} WHERE {$this->where}";
        return $this->run($sql, $this->params, $n, $alias);
    }

    private function preflight(): int
    {
        if ($this->from === '' || $this->where === '') {
            throw new LogicException('WriteScopeGuard: from() and where() are both required — no unscoped writes.');
        }
        if ($this->max === null) {
            throw new LogicException('WriteScopeGuard: expectAtMost() is required — say how many rows you mean.');
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->from} WHERE {$this->where}");
        $st->execute($this->params);
        $n = (int) $st->fetchColumn();
        if ($n === 0) {
            throw new RuntimeException('WriteScopeGuard: the predicate matches 0 rows — nothing to do, or the WHERE is wrong.');
        }
        if ($n > $this->max) {
            throw new RuntimeException("WriteScopeGuard: the predicate matches {$n} rows, more than the {$this->max} expected. Refused.");
        }
        if ($this->rollback !== null) {
            $this->writeRollback();
        }
        return $n;
    }

    private function writeRollback(): void
    {
        ['path' => $path, 'table' => $table, 'key' => $key, 'columns' => $cols] = $this->rollback;
        $alias = $this->alias();
        $select = implode(', ', array_map(fn($c) => "{$alias}.{$c}", array_merge([$key], $cols)));
        $st = $this->pdo->prepare("SELECT {$select} FROM {$this->from} WHERE {$this->where}");
        $st->execute($this->params);
        $out = "-- rollback written " . date('c') . "\n";
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sets = implode(', ', array_map(fn($c) => "{$c} = " . $this->quote($row[$c]), $cols));
            $out .= "UPDATE {$table} SET {$sets} WHERE {$key} = " . $this->quote($row[$key]) . ";\n";
        }
        if (file_put_contents($path, $out) === false) {
            throw new RuntimeException("WriteScopeGuard: could not write rollback file {$path} — refusing to write without it.");
        }
    }

    private function run(string $sql, array $params, int $expected, string $alias): int
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            $affected = $st->rowCount();
            if ($affected > $expected) {
                throw new RuntimeException("WriteScopeGuard: the write affected {$affected} rows, the count said {$expected}. Rolled back.");
            }
            $this->pdo->commit();
            return $affected;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** "accounts a" → "a"; "accounts" → "accounts". */
    private function alias(): string
    {
        $parts = preg_split('/\s+/', trim($this->from));
        return (string) end($parts);
    }

    private function quote(mixed $v): string
    {
        return $v === null ? 'NULL' : (string) $this->pdo->quote((string) $v);
    }
}
