# Samsara integration pieces

Three self-contained pieces from a production dispatch system that pushes loads to drivers' tablets
through the [Samsara](https://www.samsara.com/) fleet API and reads geofence, route-stop and document
webhooks back. Each one exists because of a failure in production. They're ported here with
synthetic data, generic names and no framework.

PHP 8.1+, no composer. `pdo_sqlite` is needed for the tests.

```bash
php integrations/samsara/tests/run_tests.php      # 32 cases
php integrations/samsara/replay/replay.php        # the attribution replay, scored
php integrations/samsara/replay/generate_fixture.php   # regenerate the synthetic fixture (deterministic)
```

```
src/
  GeoEventAttributor.php     which load does a geofence event belong to? (pure rules)
  HttpClient.php             the one outbound seam
  CurlHttpClient.php         live transport, bounded timeouts
  SamsaraWriteGate.php       off-prod: no call leaves the building; synthesized responses
  RouteCreateLock.php        per-load MySQL GET_LOCK around the create decision
  RouteStore.php             our own load → route id record (+ PDO implementation)
  ExistingRoute.php          may this dispatch reuse the route Samsara already has?
  IdempotentRouteCreator.php create a route at most once, however often the request arrives
replay/
  generate_fixture.php       synthetic fleet that reproduces the original misattribution
  fixture.json               its output
  replay.php                 point-in-time replay + score; exits 1 on any regression
tests/run_tests.php
```

---

## 1. Event attribution by physics

**Problem.** A Samsara geofence event says only that vehicle V crossed fence G at time T. It carries
no load identity. The original code attributed each event to "whatever load is active for this driver
right now". But the dispatch queue issues the driver's next load the moment the current one's unload
is recorded, while the truck is still parked at the customer. Repeat deliveries to the same customer
are routine, so the exit event landed on the *next* load and wrote a departure to a trip that hadn't
happened yet. Later that load recorded its arrival, leaving `departure < arrival`. A faster webhook
changes nothing: the departure is causally the last event of a stop, so it always arrives after the
queue has moved on.

**Approach.** Resolve by the event's own timestamp and fence, never by current state. Two rules carry
it: *a truck cannot depart a place it never arrived at*, and *a truck cannot deliver a load it hasn't
picked up*. Each `select*()` method is a pure function over candidate rows. **Null means drop the
event.** Forcing an event that fits nothing onto *some* load is exactly the bug.

**Proving it before shipping.** You can't replay history against current state: production columns
have since been backfilled and repaired, so every load now looks closed. `replay.php` rebuilds each
candidate's state *as of the moment the event was processed* from a log of every milestone write,
then drives the real selector. "As of" is strictly *before*: the event being replayed must not see
the departure it wrote. With `<=` the true load looked closed and the selector fell back to an older
stale load. That replay artifact was caught by reading the re-attributions where the old answer had
been coherent.

**Result in production:** a replay of 3,047 real events took incoherent loads from 1,652 to 0, with
0 regressions. On the synthetic fixture here: 16 → 0, 0 regressions.

## 2. A write gate for an API with no sandbox

**Problem.** Samsara has no API sandbox. The dashboard's "sandbox" is a view mode, not an isolated
tenant. A real call from a dev machine reassigns real drivers and pushes routes to real tablets.

**Approach.** `SamsaraWriteGate` wraps the transport. Anywhere but exactly `prod`, it is fail-closed:
an unset or unrecognised environment counts as not-prod. Instead of erroring, it returns a
*synthesized* response shaped per endpoint, so the calling flow completes end to end and the code
under development is still exercised. Ids are deterministic (route id = 9,000,000 + load id), and a
synthesized route lookup reports the load's own driver, so the create guard reads it as reusable.
Every synthetic body carries `_gated: true`, and every call, real or gated, goes to an audit callback.

This also shows why the gate has to look like success. A developer who watches a push "succeed" while
the fleet doesn't change will suspect the integration and try to "fix" the gate.

## 3. Creating a route at most once

**Problem.** Duplicate routes on the dispatch dashboard had three separate causes:

1. **Eventual-consistency lookup gap.** `GET /fleet/routes/<externalId>` returns 404 for 30 s or more
   after a route is created, and flaps afterwards. A guard that trusts it sees "no route" for a route
   that exists.
2. **Same-second webhook bursts.** Duplicate completion webhooks each trigger "issue the next load"
   in the same second. Samsara binds the externalId to the first create and answers the rest with
   400 "Duplicate external id", **but still materialises the losing route as an orphan**. A 400 here
   is not harmless.
3. **Reuse of ended routes.** "A route exists" was treated as success even when it had ended or
   belonged to another driver, leaving the driver with nothing on the tablet.

**Approach.** `IdempotentRouteCreator` does four steps:
- It serialises creates per load with `RouteCreateLock` (MySQL `GET_LOCK`: connection-scoped, no
  SUPER privilege, works on managed MySQL).
- It checks *our own* stored route id first, which is strongly consistent, unlike the remote lookup.
- It reuses a remote route only if `ExistingRoute` says it is live and on this driver. Otherwise it
  refuses, with a message the dispatcher can act on.
- It persists the new id *before* releasing the lock, so a waiting request finds it.

A lookup error that isn't a 404 never creates blind. The lock fails **open** when the lock
infrastructure is missing, because a dispatch must never be blocked by the lock itself. It fails
**closed** only when another create really holds it.

**Result in production:** same-second race pairs went from 7 to 1.

---

Tests were broken on purpose before being trusted. Removing the "arrival after the event" rule fails
its test. Skipping the local route-id check makes the burst test see 2 POSTs instead of 1.
