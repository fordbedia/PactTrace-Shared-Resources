<?php

namespace PactTraceSDK\SharedResources\Modules\Matter\Application\Ports\Service;

/**
 * Backs the four stat cards on `/dashboard/matters` ("Total Matters",
 * "Active", "On Hold", "Completed"). One method per card, each consumed by
 * its own Application/Action handler — see CountTotalMattersHandler /
 * CountActiveMattersHandler / CountOnHoldMattersHandler /
 * CountCompletedMattersHandler.
 */
interface MatterStatsService
{
	public function countTotal(int $providerId): int;

	public function countActive(int $providerId): int;

	public function countOnHold(int $providerId): int;

	public function countCompleted(int $providerId): int;
}
