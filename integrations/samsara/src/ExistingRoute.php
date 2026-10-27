<?php

declare(strict_types=1);

namespace AgentOps\Samsara;

/**
 * May a dispatch REUSE the Samsara route that already answers to the load's externalId?
 *
 * The create guard used to ask only "does a route exist?" and report success on yes. So a route that
 * had already ENDED, or that belonged to a different driver or to nobody, left the driver being
 * dispatched with nothing on the tablet while the queue marked the load issued. A second route can't
 * simply be created beside it either: Samsara refuses the held externalId with 400 "Duplicate
 * external id". Only a live route on the same driver is this dispatch's route.
 *
 * Note the lookup itself is not a promise: an ended route kept answering its externalId lookup the
 * next day, which is why "it exists" was never enough.
 */
final class ExistingRoute
{
    public const REUSE = 'reuse';
    public const FINISHED = 'finished';
    public const NO_DRIVER = 'no_driver';
    public const OTHER_DRIVER = 'other_driver';

    /**
     * @param array $route           the route's `data` object from GET /fleet/routes/{id}
     * @param int   $driverSamsaraId the Samsara id of the driver being dispatched
     */
    public static function classify(array $route, int $driverSamsaraId): string
    {
        if (!empty($route['actualRouteEndTime'])) {
            return self::FINISHED;
        }
        $routeDriver = (int) ($route['driver']['id'] ?? 0);
        if ($routeDriver === 0) {
            return self::NO_DRIVER;
        }

        return $routeDriver === $driverSamsaraId ? self::REUSE : self::OTHER_DRIVER;
    }

    /** The dispatcher-facing reason a non-REUSE route blocks the dispatch. */
    public static function refusal(string $verdict, array $route, int $loadId): string
    {
        $id = (string) ($route['id'] ?? '?');
        $why = match ($verdict) {
            self::FINISHED => 'that route already ended on ' . self::when((string) $route['actualRouteEndTime']),
            self::NO_DRIVER => 'that route has no driver on it',
            self::OTHER_DRIVER => 'that route belongs to ' . ((string) ($route['driver']['name'] ?? '') ?: 'another driver'),
            default => 'it cannot be reused',
        };

        return "Load {$loadId} was NOT sent to the driver: Samsara already has route {$id} for this load, and {$why}. "
            . 'Samsara refuses a second route for the same load, so nothing was sent and the load stays queued. '
            . 'Clear the old route in Samsara, then dispatch again.';
    }

    private static function when(string $iso): string
    {
        $ts = strtotime($iso);

        return $ts === false ? $iso : date('m/d H:i', $ts);
    }
}
