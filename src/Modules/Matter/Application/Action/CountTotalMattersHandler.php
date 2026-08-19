<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Action;

use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Service\MatterStatsService;

/** Backs the "Total Matters" stat card on /dashboard/matters. */
class CountTotalMattersHandler
{
	public function __construct(private MatterStatsService $service)
	{}

	public function handle(int $providerId): int
	{
		return $this->service->countTotal($providerId);
	}
}
