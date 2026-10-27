<?php

declare(strict_types=1);

namespace AgentOps\Samsara;

/**
 * Our own durable record of which Samsara route was created for which load — the strongly consistent
 * source the create guard checks before trusting Samsara's eventually consistent lookup.
 *
 * It must survive the load being unqueued or cancelled: a store that is cleared on cancel is exactly
 * where a "we never made a route" answer came from when we had.
 */
interface RouteStore
{
    public function routeIdFor(int $loadId): ?int;

    public function save(int $loadId, int $routeId): void;
}

/**
 * PDO implementation over one table:
 *   CREATE TABLE samsara_load_route (load_id INT PRIMARY KEY, route_id BIGINT NOT NULL, created_at DATETIME)
 */
final class PdoRouteStore implements RouteStore
{
    public function __construct(private \PDO $pdo, private string $table = 'samsara_load_route')
    {
    }

    public function routeIdFor(int $loadId): ?int
    {
        $st = $this->pdo->prepare("SELECT route_id FROM {$this->table} WHERE load_id = :id");
        $st->execute(['id' => $loadId]);
        $v = $st->fetchColumn();

        return $v === false || $v === null ? null : (int) $v;
    }

    public function save(int $loadId, int $routeId): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE load_id = :id")->execute(['id' => $loadId]);
        $this->pdo->prepare("INSERT INTO {$this->table} (load_id, route_id, created_at) VALUES (:id, :route, :at)")
            ->execute(['id' => $loadId, 'route' => $routeId, 'at' => date('Y-m-d H:i:s')]);
    }
}
