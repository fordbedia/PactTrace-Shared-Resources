<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services;

use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Repositories\Eloquent\EloquentMattersRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;

/**
 * Backs the "Progress" column/bar on `/dashboard/matters` and the matter
 * detail view. Progress is the percentage of a matter's milestones
 * (`Modules/Matter/Models/Milestone`) that are `completed` — the only
 * concrete signal a `Matter` carries about how far along it is, since
 * `Milestone::position` is manually ordered rather than weighted.
 *
 * Deliberately depends on the concrete `EloquentMattersRepository` (an
 * Infrastructure adapter talking to another Infrastructure adapter), not the
 * `MattersRepository` port — this class has no reason to be swappable behind
 * the port, and MatterResource (its only caller) already lives in the same
 * Infrastructure-adjacent HTTP layer.
 */
class MatterProgressCalculator
{
	public function __construct(private EloquentMattersRepository $repository)
	{}

	/**
	 * Percentage (0-100) of `$matter`'s milestones that are `completed`.
	 * Falls back to 0/100 by `status` when the matter has no milestones yet,
	 * since a 0-of-0 completion ratio has no meaningful percentage.
	 *
	 * Reuses `milestones` if the caller already eager-loaded it (every
	 * `EloquentMattersRepository::paginate*` query does); only queries
	 * through the repository when it isn't loaded, e.g. a bare `Matter`
	 * instance built outside the module's own repository.
	 */
	public function calculate(Matter $matter): int
	{
		$milestones = $matter->relationLoaded('milestones')
			? $matter->milestones
			: $this->repository->milestonesForMatter($matter->id);

		$total = $milestones->count();

		if ($total === 0) {
			return $matter->status === 'completed' ? 100 : 0;
		}

		$completed = $milestones->where('status', 'completed')->count();

		return (int) round(($completed / $total) * 100);
	}
}
