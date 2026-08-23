<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Action;

use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Services\DefaultMilestoneSeeder;

class CreateMattersHandler
{
	public function __construct(
		public MattersRepository $repository,
		private readonly DefaultMilestoneSeeder $milestoneSeeder,
	)
	{}

	public function handle($data)
	{
		$matter = $this->repository->upsert($data);

		// upsert() is a create-or-update — only a genuinely new Matter gets
		// the default milestone set; re-seeding on every update would
		// silently discard whatever milestone progress a provider already
		// recorded. See .claude/rules/matter.md, "Matter Progress timeline".
		if ($matter->wasRecentlyCreated) {
			$this->milestoneSeeder->seed($matter);
		}

		return $matter;
	}
}
