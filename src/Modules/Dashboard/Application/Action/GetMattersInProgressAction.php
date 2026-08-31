<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Dashboard\Application\Action;

use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;

/**
 * The "Matters in Progress" panel on `/dashboard` — a short, provider-scoped
 * list of the tenant's `active` / `on_hold` matters, ordered soonest-deadline
 * first (matters with no `due_date` last), then most-recently-updated. A
 * dashboard panel is most useful surfacing what is most time-sensitive.
 *
 * Progress % is NOT computed here — the matters come back with `milestones`
 * eager-loaded and MatterResource derives the percentage through
 * MatterProgressCalculator, exactly as `/dashboard/matters` and the client
 * portal already do. No second progress implementation.
 */
final class GetMattersInProgressAction
{
    /** Rows the panel shows — matches the artboard. */
    public const LIMIT = 4;

    public function __construct(private readonly MattersRepository $matters)
    {
    }

    /**
     * @return Collection<int, \PactTrackSDK\SharedResources\Modules\Matter\Models\Matter>
     */
    public function handle(int $providerId, int $limit = self::LIMIT): Collection
    {
        return $this->matters->inProgressForProvider($providerId, $limit);
    }
}
