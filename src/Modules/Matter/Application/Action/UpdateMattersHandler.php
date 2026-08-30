<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Action;

use PactTrackSDK\SharedResources\Modules\Matter\Application\DTO\MattersData;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;

/**
 * Applies an edit to an existing matter — the Matter Detail page's
 * assign/reassign-staff control (and any future field edit). Deliberately
 * does NOT seed default milestones (that is CreateMattersHandler's job,
 * guarded on `wasRecentlyCreated`) — re-seeding on every edit would discard
 * recorded milestone progress. See .claude/rules/matter.md.
 */
class UpdateMattersHandler
{
	public function __construct(private readonly MattersRepository $repository)
	{}

	public function handle(Matter $matter, MattersData $data): Matter
	{
		return $this->repository->updateMatter($matter, $data);
	}
}
