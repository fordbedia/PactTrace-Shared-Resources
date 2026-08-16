<?php

namespace PactTraceSDK\SharedResources\Modules\Matter\Application\Action;

use PactTraceSDK\SharedResources\Modules\Matter\Application\Ports\Service\MatterStatsService;

/** Backs the "On Hold" stat card on /dashboard/matters. */
class CountOnHoldMattersHandler
{
	public function __construct(private MatterStatsService $service)
	{}

	public function handle(int $providerId): int
	{
		return $this->service->countOnHold($providerId);
	}
}
