<?php
/**
 * Replay the historical destination-exit event stream through GeoEventAttributor and SCORE it.
 *
 *   php integrations/samsara/replay/replay.php [fixture.json]
 *
 * Exit 0 when the new rule leaves no incoherent load and drops no event that today's logic would
 * have written usefully; exit 1 otherwise — so it doubles as a test.
 *
 * ⭐ The hard part is POINT-IN-TIME STATE. The selector reads load milestones, and in production
 * those columns have since been backfilled and repaired — replaying against CURRENT state is
 * meaningless (every load now has a departure, so every candidate looks closed). So each candidate's
 * state is reconstructed AS OF the moment the event was processed, from the log of every milestone
 * write (field + value + when it was written), and the REAL selector is driven with those rows.
 * What is scored is literally what runs.
 *
 * Ground truth for "was the old answer wrong?": a departure written to a load that had NOT yet
 * arrived (as of that moment) is incoherent — the truck cannot leave a place it never reached.
 *
 * A DROP only loses something if the old logic would have written a departure to a load that had
 * arrived and had none yet. The setters are write-once, so an event landing on a load that already
 * had a departure was a no-op (fence jitter re-firing) — dropping those changes nothing.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/GeoEventAttributor.php';

use AgentOps\Samsara\GeoEventAttributor;

$path = $argv[1] ?? __DIR__ . '/fixture.json';
$fx = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

// Every value ever written to a milestone, and WHEN it was written.
$history = [];
foreach ($fx['history'] as $w) {
    $history[(int) $w['loadid']][$w['field']][] = ['at' => strtotime($w['at']), 'value' => $w['value']];
}

/**
 * The value of $field on $loadid as of the instant BEFORE $asOf — the first write strictly earlier
 * (setters are write-once). Strictly: the event being replayed must not see the departure it wrote
 * itself. With `<=` the true load looked already closed and the selector fell back to an older
 * stale load on the same fence — a replay artifact, caught by inspecting the re-attributions where
 * the old answer had been coherent.
 */
$valueAsOf = static function (int $loadid, string $field, int $asOf) use ($history): ?string {
    foreach ($history[$loadid][$field] ?? [] as $w) {
        if ($w['at'] < $asOf) {
            return $w['value'];
        }
    }
    return null;
};

$byDriverFence = [];
foreach ($fx['loads'] as $l) {
    $byDriverFence[$l['driverid'] . ':' . $l['dest_fence']][] = $l;
}

$attr = new GeoEventAttributor();
$agree = $changed = $dropped = $harmlessDrops = 0;
$oldIncoherent = $newIncoherent = 0;
$regressions = [];
$examples = [];

foreach ($fx['events'] as $e) {
    $old = (int) $e['attributed_loadid'];
    $t = strtotime($e['event_time']);
    $asOf = strtotime($e['processed_at']);

    $oldArrival = $valueAsOf($old, 'destination_arrival', $asOf);
    $oldBad = !GeoEventAttributor::validDateTime($oldArrival) || strtotime($oldArrival) > $t;
    $oldIncoherent += $oldBad ? 1 : 0;

    // Rebuild every candidate's state as of this event, then run the REAL selector.
    $candidates = [];
    foreach ($byDriverFence[$e['driverid'] . ':' . $e['fence']] ?? [] as $c) {
        $id = (int) $c['loadid'];
        $d = strtotime($c['deliverydate']);
        if ($d < $t - 4 * 86400 || $d > $t + 4 * 86400) {
            continue;   // same date window the production fetch uses
        }
        $candidates[] = [
            'loadid' => $id,
            'deliverydate' => $c['deliverydate'],
            'dispatched_at' => $c['dispatched_at'],
            'loadingtime' => $valueAsOf($id, 'loadingtime', $asOf),
            'destination_arrival' => $valueAsOf($id, 'destination_arrival', $asOf),
            'destination_departure' => $valueAsOf($id, 'destination_departure', $asOf),
            'unloader' => '',   // conservative: treat any departure as observed
        ];
    }

    $new = $attr->selectDestinationDeparture($candidates, $t);

    if ($new === null) {
        $dropped++;
        $wouldHaveWritten = !$oldBad && !GeoEventAttributor::validDateTime($valueAsOf($old, 'destination_departure', $asOf));
        if ($wouldHaveWritten) {
            $regressions[] = $e;
        } else {
            $harmlessDrops++;
        }
        continue;
    }

    $newArrival = $valueAsOf($new, 'destination_arrival', $asOf);
    if (!GeoEventAttributor::validDateTime($newArrival) || strtotime($newArrival) > $t) {
        $newIncoherent++;
    }
    if ($new === $old) {
        $agree++;
    } else {
        $changed++;
        if ($oldBad && count($examples) < 5) {
            $examples[] = sprintf('driver %d  exit %s at fence %d:  load %d (not arrived yet) -> load %d',
                $e['driverid'], $e['event_time'], $e['fence'], $old, $new);
        }
    }
}

$line = str_repeat('=', 72);
echo "GEO ATTRIBUTION REPLAY — " . basename($path) . "\n{$line}\n";
printf("  destination-exit events replayed     : %d\n", count($fx['events']));
printf("  same load as the old rule            : %d\n", $agree);
printf("  RE-ATTRIBUTED to a different load    : %d\n", $changed);
printf("  DROPPED (no load fits)               : %d   (%d harmless: jitter re-fires / no-ops)\n", $dropped, $harmlessDrops);
echo "\n";
printf("  incoherent loads, OLD rule           : %d   (departure written to a load that had not arrived)\n", $oldIncoherent);
printf("  incoherent loads, NEW rule           : %d\n", $newIncoherent);
printf("  REGRESSIONS (useful events dropped)  : %d   <-- must be 0\n", count($regressions));
if ($examples) {
    echo "\nexamples of the stale-queue misattribution, fixed:\n  " . implode("\n  ", $examples) . "\n";
}
foreach (array_slice($regressions, 0, 5) as $r) {
    printf("  REGRESSION: driver %d exit %s load %d\n", $r['driverid'], $r['event_time'], $r['attributed_loadid']);
}

exit(($newIncoherent === 0 && !$regressions) ? 0 : 1);
