<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Action;

use PactTrackSDK\SharedResources\Modules\Matter\Application\DTO\MattersData;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Services\MilestoneNotifier;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;

/**
 * Applies an edit to an existing matter — the Matter Detail page's
 * assign/reassign-staff control, inline status edit, and any future field
 * edit. Deliberately does NOT seed default milestones (that is
 * CreateMattersHandler's job, guarded on `wasRecentlyCreated`) — re-seeding on
 * every edit would discard recorded milestone progress. See
 * .claude/rules/matter.md.
 *
 * A change to the matter's `status` is one of the two `milestone_updated`
 * notification triggers (the other is automatic milestone completion in
 * MilestoneProgressionService); both go through MilestoneNotifier, gated on
 * the recipient's preference. See .claude/rules/notification.md.
 */
class UpdateMattersHandler
{
	public function __construct(
		private readonly MattersRepository $repository,
		private readonly MilestoneNotifier $milestoneNotifier,
	)
	{}

	public function handle(Matter $matter, MattersData $data): Matter
	{
		$previousStatus = (string) $matter->status;

		$updated = $this->repository->updateMatter($matter, $data);

		if ($previousStatus !== (string) $updated->status) {
			$this->milestoneNotifier->matterStatusChanged($updated, $previousStatus);
		}

		return $updated;
	}
}
