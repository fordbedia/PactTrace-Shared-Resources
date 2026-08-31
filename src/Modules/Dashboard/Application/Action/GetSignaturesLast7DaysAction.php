<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Dashboard\Application\Action;

use Illuminate\Support\Carbon;
use PactTrackSDK\SharedResources\Modules\Signature\Application\Port\Repository\EnvelopeReadRepository;

/**
 * The "Signatures — Last 7 Days" chart on `/dashboard` — a per-day count of
 * the tenant's envelopes that reached `completed`, over the trailing 7
 * calendar days (today included).
 *
 * Single responsibility: ask the Signature module's read repository for the
 * sparse day => count map, then project it onto a dense 7-bucket series so
 * every day is present even with zero completions (the chart must not skip
 * quiet days). Day boundaries follow the app timezone, like every other
 * "this month / this week" boundary on the dashboard.
 */
final class GetSignaturesLast7DaysAction
{
    private const DAYS = 7;

    public function __construct(private readonly EnvelopeReadRepository $envelopes)
    {
    }

    /**
     * @return list<array{date: string, count: int}> oldest day first
     */
    public function handle(int $providerId): array
    {
        $start = Carbon::now()->startOfDay()->subDays(self::DAYS - 1);

        $counts = $this->envelopes->completedCountByDaySince($providerId, $start);

        $series = [];
        for ($offset = 0; $offset < self::DAYS; $offset++) {
            $day = $start->copy()->addDays($offset)->toDateString();
            $series[] = ['date' => $day, 'count' => $counts[$day] ?? 0];
        }

        return $series;
    }
}
