<?php

declare(strict_types=1);

namespace AgentOps\Samsara;

/**
 * Create a Samsara route for a load AT MOST ONCE, however many times the request arrives.
 *
 * Three real-world causes of duplicate routes, each closed by one step below:
 *
 *  1. EVENTUAL-CONSISTENCY LOOKUP GAP. `GET /fleet/routes/<externalId>` returns 404 for ~30s+ after a
 *     route is created, and flaps 404↔200 even afterwards. A create guard that trusts it sees "no
 *     route" for a route that exists. → Step 2 checks OUR OWN stored route id first; it is strongly
 *     consistent. The remote lookup is only the fallback when we have nothing local.
 *
 *  2. SAME-SECOND WEBHOOK-BURST RACE. Duplicate completion webhooks fire "issue the next load" several
 *     times in one second; every caller passes the guard at once and POSTs. Samsara binds the
 *     externalId to the first and returns 400 "Duplicate external id" to the rest — but KEEPS the
 *     losing route object, unbound, as a visible orphan. → Step 1 serialises creates per load with
 *     RouteCreateLock, and the loser, once it gets the lock, finds the winner's id at step 2. The lock
 *     alone is not enough: the loser's remote lookup can still 404 after the winner has finished.
 *
 *  3. REUSE OF ENDED ROUTES. "A route exists" was treated as success even when that route had ended or
 *     was on another driver, leaving the driver with nothing on the tablet. → Step 3 asks
 *     ExistingRoute whether it is genuinely this dispatch's route; anything else is refused with a
 *     reason the dispatcher can act on. (Samsara won't accept a second route on the same externalId.)
 *
 * Returns ['outcome' => created|exists_local|reused|refused|busy|error, 'route_id' => ?int, 'message' => string].
 * Every outcome but `created` made no POST.
 */
final class IdempotentRouteCreator
{
    public function __construct(
        private HttpClient $samsara,
        private RouteStore $store,
        private RouteCreateLock $lock,
    ) {
    }

    /** @param array $route the POST /fleet/routes body; externalIds.loadid is set here */
    public function create(int $loadId, int $driverSamsaraId, array $route): array
    {
        // Step 1 — serialise creates for this load across requests.
        if (!$this->lock->acquire($loadId)) {
            return $this->out('busy', null, "Another request is still creating the route for load {$loadId}; nothing was sent.");
        }

        try {
            // Step 2 — our own record first: strongly consistent, unlike the externalId lookup.
            $local = $this->store->routeIdFor($loadId);
            if ($local !== null) {
                return $this->out('exists_local', $local, "Load {$loadId} already has route {$local}.");
            }

            // Step 3 — fall back to Samsara's lookup, and reuse only a live route on this driver.
            $lookup = $this->samsara->request('GET', "/fleet/routes/loadid:{$loadId}");
            if ($lookup['ok']) {
                $existing = json_decode((string) $lookup['body'], true)['data'] ?? [];
                $verdict = ExistingRoute::classify($existing, $driverSamsaraId);
                if ($verdict !== ExistingRoute::REUSE) {
                    return $this->out('refused', (int) ($existing['id'] ?? 0), ExistingRoute::refusal($verdict, $existing, $loadId));
                }
                $this->store->save($loadId, (int) $existing['id']);
                return $this->out('reused', (int) $existing['id'], "Reused live route {$existing['id']} for load {$loadId}.");
            }
            if ($lookup['status'] !== 404) {
                // Can't tell whether a route exists — never create blind.
                return $this->out('error', null, "Route lookup for load {$loadId} failed ({$lookup['error']}); nothing was sent.");
            }

            // Step 4 — create, then persist the id BEFORE releasing the lock, so a waiting loser finds it.
            $route['externalIds']['loadid'] = (string) $loadId;
            $resp = $this->samsara->request('POST', '/fleet/routes', (string) json_encode($route));
            $id = (int) (json_decode((string) $resp['body'], true)['data']['id'] ?? 0);
            if (!$resp['ok'] || $id === 0) {
                // A 400 "Duplicate external id" here may still have left an orphan route on Samsara's side.
                return $this->out('error', null, "Route create for load {$loadId} failed ({$resp['error']}).");
            }
            $this->store->save($loadId, $id);

            return $this->out('created', $id, "Created route {$id} for load {$loadId}.");
        } finally {
            $this->lock->release($loadId);
        }
    }

    private function out(string $outcome, ?int $routeId, string $message): array
    {
        return ['outcome' => $outcome, 'route_id' => $routeId, 'message' => $message];
    }
}
