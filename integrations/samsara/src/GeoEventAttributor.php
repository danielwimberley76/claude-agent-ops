<?php

declare(strict_types=1);

namespace AgentOps\Samsara;

/**
 * GeoEventAttributor — "which load does this geofence event belong to?"
 *
 * A Samsara geofence webhook carries NO load identity. It says: vehicle V crossed fence G at time T.
 * (A route-stop webhook is different — it carries the route's externalIds, so it needs none of this.)
 *
 * The original code answered the question with the driver's CURRENT QUEUE STATE — "whatever load is
 * active for this driver right now". That is wrong whenever the queue has moved on, and it moves on
 * constantly: the queue completes a load when its unload is recorded and immediately issues the next
 * one, while the truck is still sitting at the customer. When the next load goes to the SAME customer —
 * routine for repeat deliveries — the exit event fires, resolves to the NEW load, and writes a departure
 * to a trip that hasn't happened yet. The new load records its arrival later: `departure < arrival`.
 *
 * It is NOT a latency problem — most of those events arrived within minutes, and the departure is
 * causally the LAST event of a stop, so it always arrives after the queue has moved on. A faster
 * webhook changes nothing.
 *
 * So: resolve by the event's own TIMESTAMP + FENCE, never by what is active now. Two rules carry it:
 *   - A truck cannot depart a place it never arrived at.
 *   - A truck cannot deliver a load it has not picked up.
 *
 * Every select*() method is a PURE function over candidate rows (the driver's loads near that date) —
 * no I/O — so the offline replay harness (../replay/replay.php) drives exactly the code that runs in
 * production against point-in-time reconstructed state. Do not inline a fetch into them.
 *
 * Returns null when nothing fits. Null means DROP the event: a departure with no arrived, unclosed load
 * at that fence is not information, and forcing it onto SOME load is exactly the bug. Log the drops —
 * a rising drop count means the rules have become too strict.
 *
 * Candidate row keys: loadid, deliverydate, dispatched_at, loadingtime, unloadingtime,
 * origin_fence, origin_arrival, origin_departure, destination_arrival, destination_departure, unloader.
 */
final class GeoEventAttributor
{
    /** Written to `unloader` when a departure was ESTIMATED rather than observed. */
    public const DEPARTURE_NOT_CAPTURED = 'n/a - departure not captured';

    /**
     * The load this truck is LEAVING (destination exit). It must have arrived at or before T, and not
     * yet have a departure we actually observed. Most recent such arrival wins: the trip it is on.
     */
    public function selectDestinationDeparture(array $candidates, int $t): ?int
    {
        $best = null;
        foreach ($candidates as $c) {
            $arrival = $c['destination_arrival'] ?? null;

            // Cannot leave a place it never arrived at, and an arrival AFTER this event belongs to a
            // later trip — precisely the load we must not write to.
            if (!self::validDateTime($arrival) || strtotime($arrival) > $t) {
                continue;
            }
            if ($this->departureIsClosed($c)) {
                continue;
            }
            if ($best === null || strtotime($arrival) > strtotime($best['destination_arrival'])) {
                $best = $c;
            }
        }

        return $best ? (int) $best['loadid'] : null;
    }

    /**
     * The load this truck is DELIVERING (destination entry). It must be loaded by T — a truck cannot
     * deliver a load it has not picked up, and that single test is what excludes the driver's
     * freshly-issued next load, the one that caused the misattribution. Earliest such load wins.
     */
    public function selectDestinationArrival(array $candidates, int $t): ?int
    {
        $best = null;
        foreach ($candidates as $c) {
            if (self::validDateTime($c['destination_arrival'] ?? null)) {
                continue;   // already arrived — first write wins
            }
            if (!$this->isLoadedBy($c, $t)) {
                continue;   // not picked up yet, so this delivery is not it
            }
            if ($best === null || $this->sortKey($best) > $this->sortKey($c)) {
                $best = $c;
            }
        }

        return $best ? (int) $best['loadid'] : null;
    }

    /**
     * An origin (terminal) event belongs ONLY to the driver's EARLIEST UNFINISHED load — dispatched by
     * T, not yet unloaded by T. If that load does not load at this fence, or already carries this stamp,
     * the event is dropped.
     *
     * The rule this replaced took ANY dispatched, unstamped load at the fence, so on a shuttle driver
     * with several loads dispatched at once, rack jitter (exit, re-entry, exit) burned the NEXT load's
     * origin stamps hours before it was picked up.
     *
     * Unlike the destination side, an origin departure does NOT require an arrival on record: the
     * terminal's entry fence is routinely missed, and the arrival is backfilled from the exit.
     *
     * @param string $field 'origin_arrival' | 'origin_departure'
     */
    public function selectOriginEvent(array $candidates, int $fenceId, int $t, string $field): ?int
    {
        $first = null;
        foreach ($candidates as $c) {
            $sent = $c['dispatched_at'] ?? null;
            if (!self::validDateTime($sent) || strtotime($sent) > $t) {
                continue;   // not dispatched to this driver yet at the moment of the event
            }
            $unloaded = $c['unloadingtime'] ?? null;
            if (self::validDateTime($unloaded) && strtotime($unloaded) <= $t) {
                continue;   // finished — this visit cannot be for it
            }
            if ($first === null || $this->sortKey($first) > $this->sortKey($c)) {
                $first = $c;
            }
        }

        if ($first === null || (int) ($first['origin_fence'] ?? 0) !== $fenceId) {
            return null;   // the load the driver is on does not load here
        }
        if (self::validDateTime($first[$field] ?? null)) {
            return null;   // already stamped: a jitter re-fire, never the next load's
        }
        $arrival = $first['origin_arrival'] ?? null;
        if ($field === 'origin_departure' && self::validDateTime($arrival) && strtotime($arrival) > $t) {
            return null;   // arrived after this exit — the exit belongs to an earlier visit
        }

        return (int) $first['loadid'];
    }

    /**
     * A departure we actually OBSERVED closes the load to further departure events. A COLLAPSED one
     * (== arrival, or earlier) or our own flagged ESTIMATE does not — those are exactly the rows a later
     * real departure must still be able to repair.
     */
    private function departureIsClosed(array $c): bool
    {
        if (!self::isCredibleDeparture($c['destination_departure'] ?? null, $c['destination_arrival'] ?? null)) {
            return false;
        }

        return ($c['unloader'] ?? '') !== self::DEPARTURE_NOT_CAPTURED;
    }

    /**
     * Loaded at the terminal by T. `loadingtime` ONLY — origin_departure is itself a geofence guess, and
     * rack jitter can stamp it on the NEXT load hours early, which would make the next terminal entry
     * look like that load's "delivery".
     */
    private function isLoadedBy(array $c, int $t): bool
    {
        $v = $c['loadingtime'] ?? null;

        return self::validDateTime($v) && strtotime($v) <= $t;
    }

    /** Stable ordering: oldest delivery date, then lowest load id. */
    private function sortKey(array $c): string
    {
        return ($c['deliverydate'] ?? '9999-12-31') . '|' . str_pad((string) ($c['loadid'] ?? 0), 12, '0', STR_PAD_LEFT);
    }

    /** A departure is credible when it exists and is strictly after the arrival (if any). */
    public static function isCredibleDeparture(?string $departure, ?string $arrival): bool
    {
        if (!self::validDateTime($departure)) {
            return false;
        }
        if (!self::validDateTime($arrival)) {
            return true;
        }

        return strtotime($departure) > strtotime($arrival);
    }

    /** `Y-m-d H:i:s` (or with a T separator), and not a zero-date sentinel. */
    public static function validDateTime(?string $v): bool
    {
        if ($v === null || $v === '' || str_starts_with($v, '0000-00-00')) {
            return false;
        }
        $v = str_replace('T', ' ', $v);
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $v);

        return $d !== false && $d->format('Y-m-d H:i:s') === $v;
    }
}
