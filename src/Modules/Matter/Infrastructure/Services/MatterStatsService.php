<?php

namespace PactTraceSDK\SharedResources\Modules\Matter\Infrastructure\Services;

use PactTraceSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;
use PactTraceSDK\SharedResources\Modules\Matter\Application\Ports\Service\MatterStatsService as MatterStatsServiceContract;

class MatterStatsService implements MatterStatsServiceContract
{
	public function __construct(private MattersRepository $repository)
	{}

	public function countTotal(int $providerId): int
	{
		return $this->repository->countAll($providerId);
	}

	public function countActive(int $providerId): int
	{
		return $this->repository->countActive($providerId);
	}

	public function countOnHold(int $providerId): int
	{
		return $this->repository->countOnHold($providerId);
	}

	public function countCompleted(int $providerId): int
	{
		return $this->repository->countCompleted($providerId);
	}
}
