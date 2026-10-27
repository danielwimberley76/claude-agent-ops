<?php

declare(strict_types=1);

namespace AgentOps\Samsara;

/**
 * A per-load advisory lock around the route-CREATE decision, on MySQL GET_LOCK / RELEASE_LOCK.
 *
 * Why: when a load delivers, Samsara's completion signals arrive in bursts — a route-stop departure
 * and a geofence entry, each sometimes delivered more than once — and each one triggers "issue the
 * driver's next load", several times in the SAME SECOND. Without serialisation every one of them sees
 * "no route yet" and POSTs a create. Samsara binds the externalId to the first and answers the rest
 * with 400 "Duplicate external id" — but it still MATERIALISES the losing route object, unbound,
 * which shows on the dispatch dashboard as a duplicate, empty route. A 400 here is NOT harmless noise.
 *
 * GET_LOCK is connection-scoped, gives cross-request mutual exclusion (each webhook is its own PHP
 * request and DB connection), and needs no SUPER privilege — so it works on managed MySQL where
 * triggers are not allowed.
 *
 * ⚠️ FAIL-OPEN when the lock infrastructure is absent or errors (SQLite in tests, a killed session):
 * a dispatch must never be blocked because the lock itself is unavailable. It fails CLOSED only when
 * another create genuinely holds the lock past the timeout. Both open paths are reported to $log.
 */
class RouteCreateLock
{
    private const PREFIX = 'samsara_route_create_';

    /**
     * Comfortably longer than the winner's create path (POST + stop documents + persisting the id),
     * so the loser waits until the id is stored, then finds it locally and skips its own create.
     */
    public const TIMEOUT_SECONDS = 20;

    /** @var callable(string, array): void */
    private $log;

    public function __construct(private \PDO $pdo, ?callable $log = null)
    {
        $this->log = $log ?? static function (string $msg, array $ctx): void {
        };
    }

    /**
     * @return bool true when safe to proceed (lock held, or lock infra absent); false only when another
     *              create holds the lock past the timeout — the caller must NOT create in that case.
     */
    public function acquire(int $loadId, int $timeout = self::TIMEOUT_SECONDS): bool
    {
        try {
            // The timeout is a trusted int, inlined: binding it as a string param invites the same
            // numeric-coercion surprise as LIMIT.
            $st = $this->pdo->prepare('SELECT GET_LOCK(:name, ' . (int) $timeout . ') AS acquired');
            $st->execute(['name' => self::PREFIX . $loadId]);
            $acquired = $st->fetchColumn();
        } catch (\Throwable $e) {
            ($this->log)('GET_LOCK unavailable — proceeding without lock', ['loadid' => $loadId, 'error' => $e->getMessage()]);
            return true;
        }

        if ((string) $acquired === '1') {
            return true;
        }
        if ((string) $acquired === '0') {
            return false;   // timed out: another create holds it
        }
        // NULL: a lock error (session killed, deadlock). Don't block a dispatch over lock infra.
        ($this->log)('GET_LOCK returned NULL — proceeding without lock', ['loadid' => $loadId]);
        return true;
    }

    public function release(int $loadId): void
    {
        try {
            $st = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $st->execute(['name' => self::PREFIX . $loadId]);
        } catch (\Throwable $e) {
            // No lock to release on an engine without GET_LOCK.
        }
    }
}
