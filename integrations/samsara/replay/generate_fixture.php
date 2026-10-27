<?php
/**
 * Generate the SYNTHETIC replay fixture (fixture.json). Deterministic: same seed, same file.
 *
 *   php integrations/samsara/replay/generate_fixture.php
 *
 * Simulates a small fleet the way the ORIGINAL system behaved, so the replay has realistic
 * incoherence to find:
 *
 *   - Each driver runs a chain of loads. The dispatch queue issues the next load the moment the
 *     current one's unload is recorded — while the truck is still parked at the customer.
 *   - Repeat deliveries to the same customer are common (the stale-queue case).
 *   - Sometimes the unload is keyed AFTER the truck has left, so the queue had not moved on yet.
 *   - Fences jitter: some exits fire twice a few minutes apart.
 *
 * The OLD attribution rule ("whatever load is active for this driver when the event is processed")
 * decides which load each destination-exit event was written to, write-once — exactly as the
 * original system recorded it. Events where the old rule's load did not match the fence are not
 * recorded at all (the old code silently ignored them), mirroring the real event log.
 *
 * All ids, fences and times are invented.
 */
declare(strict_types=1);

mt_srand(20260105);

const DRIVERS = [101, 102, 103, 104, 105, 106];
const CUSTOMER_FENCES = [501, 502, 503, 504, 505, 506];
const ORIGIN_FENCES = [601, 602];
const LOADS_PER_DRIVER = 8;

$fmt = static fn(int $ts): string => date('Y-m-d H:i:s', $ts);
$mins = static fn(int $lo, int $hi): int => mt_rand($lo, $hi) * 60;

$loads = [];
$history = [];
$nextLoadId = 9001;

foreach (DRIVERS as $driver) {
    $clock = strtotime('2026-01-05 06:00:00') + $mins(0, 60);
    $prevFence = null;
    $prevDepart = null;
    for ($i = 0; $i < LOADS_PER_DRIVER; $i++) {
        $id = $nextLoadId++;
        // 45% of the time, the next load goes to the SAME customer — the case that breaks the old rule.
        $fence = ($prevFence !== null && mt_rand(1, 100) <= 45)
            ? $prevFence
            : CUSTOMER_FENCES[array_rand(CUSTOMER_FENCES)];
        $dispatched = $clock;   // issued when the previous unload was recorded
        $loading = max($dispatched, $prevDepart ?? $dispatched) + $mins(45, 90);
        $arrival = $loading + $mins(60, 150);
        $depart = $arrival + $mins(40, 90);
        // Most unloads are keyed while the truck is still on site (before it leaves) — which issues
        // the next load early. Some are keyed after it has left.
        $unload = mt_rand(1, 100) <= 60 ? $depart - $mins(5, 25) : $depart + $mins(10, 40);

        $loads[] = [
            'loadid' => $id,
            'driverid' => $driver,
            'deliverydate' => date('Y-m-d', $arrival),
            'dest_fence' => $fence,
            'origin_fence' => ORIGIN_FENCES[array_rand(ORIGIN_FENCES)],
            'dispatched_at' => $fmt($dispatched),
            '_true_departure' => $fmt($depart),
        ];
        $history[] = ['loadid' => $id, 'field' => 'loadingtime', 'value' => $fmt($loading), 'at' => $fmt($loading + 60)];
        $history[] = ['loadid' => $id, 'field' => 'destination_arrival', 'value' => $fmt($arrival), 'at' => $fmt($arrival + 60)];
        $history[] = ['loadid' => $id, 'field' => 'unloadingtime', 'value' => $fmt($unload), 'at' => $fmt($unload)];

        $prevFence = $fence;
        $prevDepart = $depart;
        $clock = $unload;
    }
}

// ── the OLD system's behaviour over the destination-exit events ──────────────────────────────
$byDriver = [];
foreach ($loads as $l) {
    $byDriver[$l['driverid']][] = $l;
}
$unloadAt = [];
foreach ($history as $h) {
    if ($h['field'] === 'unloadingtime') {
        $unloadAt[$h['loadid']] = strtotime($h['value']);
    }
}

$events = [];
$departureWritten = [];
foreach ($loads as $l) {
    $exits = [strtotime($l['_true_departure'])];
    if (mt_rand(1, 100) <= 15) {
        $exits[] = $exits[0] + $mins(2, 6);   // fence jitter: a second exit a few minutes later
    }
    foreach ($exits as $t) {
        $processed = $t + mt_rand(5, 240);   // webhook latency: seconds to a few minutes
        // OLD rule: the driver's active queue head at processing time.
        $head = null;
        foreach ($byDriver[$l['driverid']] as $c) {
            if (strtotime($c['dispatched_at']) <= $processed && $unloadAt[$c['loadid']] > $processed) {
                $head = $c;
                break;
            }
        }
        if ($head === null || $head['dest_fence'] !== $l['dest_fence']) {
            continue;   // the old code ignored an exit at a fence its active load didn't go to
        }
        $events[] = [
            'driverid' => $l['driverid'],
            'fence' => $l['dest_fence'],
            'event_time' => $fmt($t),
            'processed_at' => $fmt($processed),
            'attributed_loadid' => $head['loadid'],
        ];
        if (!isset($departureWritten[$head['loadid']])) {   // the setter is write-once
            $departureWritten[$head['loadid']] = true;
            $history[] = ['loadid' => $head['loadid'], 'field' => 'destination_departure', 'value' => $fmt($t), 'at' => $fmt($processed)];
        }
    }
}

usort($history, static fn($a, $b) => strcmp($a['at'], $b['at']));
usort($events, static fn($a, $b) => strcmp($a['processed_at'], $b['processed_at']));
foreach ($loads as &$l) {
    unset($l['_true_departure']);
}
unset($l);

$out = __DIR__ . '/fixture.json';
file_put_contents($out, json_encode([
    'note' => 'SYNTHETIC fixture generated by generate_fixture.php — invented ids, fences and times.',
    'loads' => $loads,
    'history' => $history,
    'events' => $events,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

printf("wrote %s — %d loads, %d history writes, %d destination-exit events\n",
    basename($out), count($loads), count($history), count($events));
