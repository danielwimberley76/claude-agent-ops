<?php

declare(strict_types=1);

namespace AgentOps\Samsara;

/**
 * The off-prod gate: anywhere but prod, no Samsara call leaves the building.
 *
 * ⛔ Samsara has NO API sandbox. The "sandbox" in their dashboard is a view mode, not an isolated
 * tenant, so any real call from a dev machine mutates the actual production fleet — reassigns
 * drivers, pushes routes to real tablets. This gate is the only thing between local development and
 * live trucks, and it is deliberately FAIL-CLOSED: an environment that is unset or unrecognised is
 * treated as not-prod.
 *
 * A developer watching a route push "succeed" while the live fleet doesn't change will reasonably
 * suspect the integration is broken and try to "fix" the gate. The synthesized responses exist so
 * that doesn't happen: they are shaped per endpoint, so the calling flow completes end to end (a
 * route id gets stored, a document gets recorded) and the code under development is still exercised.
 * Every body carries `_gated: true` so audits and tests can tell synthetic from real.
 *
 * Every call, real or gated, goes to the audit callback — a gated one with status -1, so an API
 * monitor can filter them out.
 */
final class SamsaraWriteGate implements HttpClient
{
    /** @var callable(): ?string */
    private $env;
    /** @var callable(array): void */
    private $audit;
    /** @var callable(int): int */
    private $driverForLoad;

    /**
     * @param HttpClient    $live          the real transport — only ever called when env === 'prod'
     * @param callable      $env           returns the current environment name, or null if unresolved
     * @param callable|null $audit         receives ['method','url','body','status','response','gated']
     * @param callable|null $driverForLoad load id -> Samsara driver id, so a synthesized route lookup
     *                                     reports the load's OWN driver (the create guard then reads it
     *                                     as reusable instead of refusing every dev dispatch)
     */
    public function __construct(
        private HttpClient $live,
        callable $env,
        ?callable $audit = null,
        ?callable $driverForLoad = null,
    ) {
        $this->env = $env;
        $this->audit = $audit ?? static function (array $row): void {
        };
        $this->driverForLoad = $driverForLoad ?? static fn(int $loadId): int => 0;
    }

    public function request(string $method, string $url, string $body = ''): array
    {
        $env = ($this->env)();

        // Non-prod is fail-closed: Samsara has no sandbox — see the class comment.
        if ($env !== 'prod') {
            $synth = $this->synthesize($method, $url, $body !== '' ? json_decode($body, true) : null);
            ($this->audit)(['method' => $method, 'url' => $url, 'body' => $body, 'status' => -1,
                'response' => $synth, 'gated' => true, 'env' => $env ?? 'unset']);

            return ['ok' => true, 'status' => 200, 'body' => $synth, 'error' => null];
        }

        $result = $this->live->request($method, $url, $body);
        ($this->audit)(['method' => $method, 'url' => $url, 'body' => $body, 'status' => $result['status'] ?? 0,
            'response' => $result['body'], 'gated' => false, 'env' => $env]);

        return $result;
    }

    /**
     * A JSON body shaped to what the caller expects from this endpoint. Ids are deterministic where
     * possible (route id = 9,000,000 + load id), so the same dev load produces the same synthetic
     * route across calls instead of a new one each time.
     */
    public function synthesize(string $method, string $url, ?array $body): string
    {
        // Not parse_url(): it returns false for a relative path whose segment looks like host:port
        // (`/fleet/routes/loadid:123`), which silently turned every route lookup into an empty body.
        $path = (string) preg_replace('#^https?://[^/]+#', '', strtok($url, '?'));
        $loadId = $this->loadIdFromContext($url, $body);
        $routeId = 9000000 + max(1, $loadId);
        $payload = ['_gated' => true];

        if ($method === 'POST' && $path === '/fleet/routes') {
            $payload['data'] = ['id' => $routeId];
        } elseif ($method === 'PATCH' && str_starts_with($path, '/fleet/routes/')) {
            $payload['data'] = ['id' => $routeId, 'stops' => []];
        } elseif ($method === 'DELETE' && str_starts_with($path, '/fleet/routes/')) {
            $payload['data'] = [];
        } elseif ($method === 'GET' && str_starts_with($path, '/fleet/routes/')) {
            // Scheduled, not started, not ended — on the load's own driver.
            $payload['data'] = [
                'id' => $routeId,
                'driver' => ['id' => (string) ($loadId > 0 ? ($this->driverForLoad)($loadId) : 0)],
                'actualRouteStartTime' => null,
                'actualRouteEndTime' => null,
                'stops' => [
                    ['id' => 9300000 + max(1, $loadId), 'eta' => date(DATE_RFC3339, strtotime('+1 hour'))],
                    ['id' => 9300001 + max(1, $loadId), 'eta' => date(DATE_RFC3339, strtotime('+3 hours'))],
                ],
            ];
        } elseif ($method === 'POST' && $path === '/fleet/drivers') {
            $payload['data'] = ['id' => 9100000 + crc32($body['name'] ?? '') % 1000];
        } elseif ($method === 'GET' && str_starts_with($path, '/fleet/drivers/')) {
            $payload['data'] = ['id' => (int) basename($path), 'name' => 'Gated Driver'];
        } elseif ($method === 'POST' && $path === '/fleet/documents') {
            $payload['data'] = ['id' => 'gated-doc-' . max(0, $loadId)];
        } elseif ($method === 'POST' && $path === '/addresses') {
            $payload['data'] = ['id' => 9200000 + crc32($body['name'] ?? '') % 1000];
        } else {
            $payload['data'] = $method === 'GET' ? [] : null;
        }

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    /** The load a call is about: `…/routes/loadid:123` in the URL, or externalIds.loadid in the body. */
    private function loadIdFromContext(string $url, ?array $body): int
    {
        if (preg_match('#loadid:(\d+)#', $url, $m)) {
            return (int) $m[1];
        }

        return (int) ($body['externalIds']['loadid'] ?? 0);
    }
}
