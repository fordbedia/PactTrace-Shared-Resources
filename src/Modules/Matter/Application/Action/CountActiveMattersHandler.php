<?php

namespace PactTraceSDK\SharedResources\Modules\Matter\Application\Action;

use PactTraceSDK\SharedResources\Modules\Matter\Application\Ports\Service\MatterStatsService;

/** Backs the "Active" stat card on /dashboard/matters. */
class CountActiveMattersHandler
{
	public function __construct(private MatterStatsService $service)
	{}

	public function handle(int $providerId): int
	{
		return $this->service->countActive($providerId);
	}
}
