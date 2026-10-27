<?php
/**
 * Plain-PHP tests for integrations/samsara — no composer, no framework.
 *
 *   php integrations/samsara/tests/run_tests.php
 *
 * Exit 1 on any failure. Needs pdo_sqlite (for the route store).
 */
declare(strict_types=1);

$src = __DIR__ . '/../src';
foreach (['GeoEventAttributor', 'HttpClient', 'SamsaraWriteGate', 'RouteCreateLock', 'RouteStore', 'ExistingRoute', 'IdempotentRouteCreator'] as $f) {
    require_once "{$src}/{$f}.php";
}

use AgentOps\Samsara\ExistingRoute;
use AgentOps\Samsara\GeoEventAttributor;
use AgentOps\Samsara\HttpClient;
use AgentOps\Samsara\IdempotentRouteCreator;
use AgentOps\Samsara\PdoRouteStore;
use AgentOps\Samsara\RouteCreateLock;
use AgentOps\Samsara\SamsaraWriteGate;

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo '  [' . ($ok ? 'PASS' : 'FAIL') . "] {$name}" . ($ok || $detail === '' ? '' : "   ({$detail})") . "\n";
}

/** A fake transport that records every call and answers from a script. */
final class FakeSamsara implements HttpClient
{
    public array $calls = [];
    /** @param callable(string, string, string): array $answer */
    public function __construct(private $answer)
    {
    }
    public function request(string $method, string $url, string $body = ''): array
    {
        $this->calls[] = "{$method} {$url}";
        return ($this->answer)($method, $url, $body);
    }
    public function count(string $prefix): int
    {
        return count(array_filter($this->calls, static fn($c) => str_starts_with($c, $prefix)));
    }
}

// ── 1. GeoEventAttributor rules ──────────────────────────────────────────────
echo "GeoEventAttributor\n";
$a = new GeoEventAttributor();
$t = strtotime('2026-01-05 10:20:00');

// The stale-queue case: A was delivered and unloaded; the queue already issued B to the SAME customer.
$loadA = ['loadid' => 1, 'deliverydate' => '2026-01-05', 'loadingtime' => '2026-01-05 07:00:00',
    'destination_arrival' => '2026-01-05 09:30:00', 'destination_departure' => null, 'unloadingtime' => '2026-01-05 10:00:00'];
$loadB = ['loadid' => 2, 'deliverydate' => '2026-01-05', 'loadingtime' => null,
    'destination_arrival' => null, 'destination_departure' => null, 'unloadingtime' => null];
check('exit goes to the load that arrived, not the freshly issued next one', $a->selectDestinationDeparture([$loadB, $loadA], $t) === 1);
check('no arrived load at the fence -> null (drop the event)', $a->selectDestinationDeparture([$loadB], $t) === null);
check('an arrival AFTER the event is a later trip, not this one',
    $a->selectDestinationDeparture([array_merge($loadA, ['destination_arrival' => '2026-01-05 11:00:00'])], $t) === null);
check('an observed departure closes the load (jitter re-fire is dropped)',
    $a->selectDestinationDeparture([array_merge($loadA, ['destination_departure' => '2026-01-05 10:15:00'])], $t) === null);
check('a COLLAPSED departure (== arrival) stays open to the real one',
    $a->selectDestinationDeparture([array_merge($loadA, ['destination_departure' => '2026-01-05 09:30:00'])], $t) === 1);
check('an ESTIMATED departure stays open to the real one',
    $a->selectDestinationDeparture([array_merge($loadA, ['destination_departure' => '2026-01-05 10:05:00',
        'unloader' => GeoEventAttributor::DEPARTURE_NOT_CAPTURED])], $t) === 1);
check('entry goes only to a load already picked up', $a->selectDestinationArrival([
    array_merge($loadA, ['destination_arrival' => null]), $loadB], $t) === 1);
check('entry for a load not yet loaded -> null', $a->selectDestinationArrival([$loadB], $t) === null);

$o1 = ['loadid' => 10, 'deliverydate' => '2026-01-05', 'dispatched_at' => '2026-01-05 05:00:00', 'origin_fence' => 601,
    'origin_arrival' => '2026-01-05 06:00:00', 'origin_departure' => '2026-01-05 06:40:00', 'unloadingtime' => null];
$o2 = ['loadid' => 11, 'deliverydate' => '2026-01-06', 'dispatched_at' => '2026-01-05 05:00:00', 'origin_fence' => 601,
    'origin_arrival' => null, 'origin_departure' => null, 'unloadingtime' => null];
check("terminal re-exit jitter does NOT burn the next load's stamp",
    $a->selectOriginEvent([$o1, $o2], 601, strtotime('2026-01-05 06:50:00'), 'origin_departure') === null);
check('terminal event at a fence the current load does not load at -> null',
    $a->selectOriginEvent([$o1], 602, strtotime('2026-01-05 06:50:00'), 'origin_arrival') === null);

// ── 2. the replay on the synthetic fixture ───────────────────────────────────
echo "replay (synthetic fixture)\n";
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../replay/replay.php') . ' 2>&1', $out, $rc);
$text = implode("\n", $out);
preg_match('/incoherent loads, OLD rule\s+:\s+(\d+)/', $text, $old);
preg_match('/incoherent loads, NEW rule\s+:\s+(\d+)/', $text, $new);
preg_match('/REGRESSIONS.*:\s+(\d+)/', $text, $reg);
check('fixture contains stale-queue misattributions under the old rule', (int) ($old[1] ?? 0) > 0, 'old=' . ($old[1] ?? '?'));
check('new rule leaves 0 incoherent loads', ($new[1] ?? '') === '0', 'new=' . ($new[1] ?? '?'));
check('0 regressions (no useful event dropped)', ($reg[1] ?? '') === '0', 'regressions=' . ($reg[1] ?? '?'));
check('replay exits 0', $rc === 0, "exit {$rc}");

// ── 3. the no-sandbox write gate ─────────────────────────────────────────────
echo "SamsaraWriteGate\n";
$live = new FakeSamsara(static fn() => ['ok' => true, 'status' => 200, 'body' => '{"data":{"id":42}}', 'error' => null]);
$audit = [];
// A full closure, not fn(): an arrow function captures $audit BY VALUE, so the by-reference `use`
// inside it would bind to the arrow function's private copy and the test would see nothing.
$gate = static function (?string $env) use ($live, &$audit): SamsaraWriteGate {
    return new SamsaraWriteGate($live, static fn() => $env, static function (array $r) use (&$audit) {
        $audit[] = $r;
    }, static fn(int $loadId) => 7001);
};

$r = $gate('dev')->request('POST', '/fleet/routes', '{"externalIds":{"loadid":"123"}}');
$body = json_decode($r['body'], true);
check('dev: the live transport is never called', $live->calls === []);
check('dev: synthesized route id is deterministic (9,000,000 + load id)', ($body['data']['id'] ?? 0) === 9000123);
check('dev: synthetic body is marked _gated', ($body['_gated'] ?? false) === true);
check('dev: gated call is audited with status -1', ($audit[0]['status'] ?? 0) === -1 && $audit[0]['gated'] === true);
$r = $gate('dev')->request('GET', '/fleet/routes/loadid:123');
$body = json_decode($r['body'], true);
check("dev: a synthesized route lookup is live and on the load's own driver",
    ($body['data']['driver']['id'] ?? '') === '7001' && $body['data']['actualRouteEndTime'] === null);
$gate(null)->request('DELETE', '/fleet/routes/loadid:123');
check('UNSET environment is gated too (fail-closed)', $live->calls === []);
$gate('production')->request('DELETE', '/fleet/routes/loadid:123');
check("an unrecognised name ('production') is gated — only exactly 'prod' goes live", $live->calls === []);
$r = $gate('prod')->request('POST', '/fleet/routes', '{}');
check('prod: the live transport IS called and its answer returned', $live->calls === ['POST /fleet/routes'] && $r['body'] === '{"data":{"id":42}}');

// ── 4. idempotent route create ───────────────────────────────────────────────
echo "IdempotentRouteCreator\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE samsara_load_route (load_id INTEGER PRIMARY KEY, route_id INTEGER NOT NULL, created_at TEXT)');
$lockLog = [];
$lock = new RouteCreateLock($pdo, static function (string $m) use (&$lockLog) {
    $lockLog[] = $m;
});
check('no GET_LOCK on this engine -> acquire() fails OPEN', $lock->acquire(1) === true);
check('...and says so', count($lockLog) === 1);

// Samsara's lookup lags: it 404s for a route that already exists (eventual consistency).
$nextId = 555000;
$samsara = new FakeSamsara(static function (string $m) use (&$nextId) {
    return $m === 'GET'
        ? ['ok' => false, 'status' => 404, 'body' => '{}', 'error' => 'HTTP 404']
        : ['ok' => true, 'status' => 200, 'body' => json_encode(['data' => ['id' => ++$nextId]]), 'error' => null];
});
$creator = new IdempotentRouteCreator($samsara, new PdoRouteStore($pdo), $lock);
$first = $creator->create(321, 7001, ['name' => 'Load 321']);
$second = $creator->create(321, 7001, ['name' => 'Load 321']);   // the burst's second webhook
check('first request creates the route', $first['outcome'] === 'created' && $first['route_id'] === 555001);
check('second request finds the LOCAL id despite the lagging lookup — no second POST',
    $second['outcome'] === 'exists_local' && $samsara->count('POST') === 1, 'POSTs=' . $samsara->count('POST'));

$ended = new FakeSamsara(static fn(string $m) => $m === 'GET'
    ? ['ok' => true, 'status' => 200, 'body' => json_encode(['data' => ['id' => 777, 'driver' => ['id' => '7001'],
        'actualRouteEndTime' => '2026-01-04T18:00:00Z']]), 'error' => null]
    : ['ok' => true, 'status' => 200, 'body' => '{"data":{"id":1}}', 'error' => null]);
$r = (new IdempotentRouteCreator($ended, new PdoRouteStore($pdo), $lock))->create(400, 7001, []);
check('an ENDED route is refused, not reused — and nothing is POSTed', $r['outcome'] === 'refused' && $ended->count('POST') === 0);
check('the refusal tells the dispatcher what to do', str_contains($r['message'], 'Clear the old route'));

$live7002 = new FakeSamsara(static fn() => ['ok' => true, 'status' => 200,
    'body' => json_encode(['data' => ['id' => 888, 'driver' => ['id' => '7002', 'name' => 'Driver B']]]), 'error' => null]);
$r = (new IdempotentRouteCreator($live7002, new PdoRouteStore($pdo), $lock))->create(401, 7001, []);
check("another driver's live route is refused", $r['outcome'] === 'refused' && str_contains($r['message'], 'Driver B'));
$r = (new IdempotentRouteCreator($live7002, new PdoRouteStore($pdo), $lock))->create(402, 7002, []);
check("the same driver's live route is reused and stored locally",
    $r['outcome'] === 'reused' && (new PdoRouteStore($pdo))->routeIdFor(402) === 888);

$flaky = new FakeSamsara(static fn() => ['ok' => false, 'status' => 503, 'body' => null, 'error' => 'HTTP 503']);
$r = (new IdempotentRouteCreator($flaky, new PdoRouteStore($pdo), $lock))->create(403, 7001, []);
check('a lookup ERROR (not a 404) never creates blind', $r['outcome'] === 'error' && $flaky->count('POST') === 0);

$held = new class ($pdo) extends RouteCreateLock {
    public function acquire(int $loadId, int $timeout = self::TIMEOUT_SECONDS): bool
    {
        return false;   // another request holds the lock past the timeout
    }
};
$busy = new FakeSamsara(static fn() => ['ok' => true, 'status' => 200, 'body' => '{}', 'error' => null]);
$r = (new IdempotentRouteCreator($busy, new PdoRouteStore($pdo), $held))->create(404, 7001, []);
check('lock held elsewhere -> busy, and no call to Samsara at all', $r['outcome'] === 'busy' && $busy->calls === []);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
