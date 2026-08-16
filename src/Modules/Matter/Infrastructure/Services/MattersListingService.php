<?php

namespace PactTraceSDK\SharedResources\Modules\Matter\Infrastructure\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use PactTraceSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;
use PactTraceSDK\SharedResources\Modules\Matter\Application\Ports\Service\MattersListingService as MattersListingServiceContract;

class MattersListingService implements MattersListingServiceContract
{
	public function __construct(private MattersRepository $repository)
	{}

	public function paginate(int $providerId, string $filter, int $perPage, ?int $page): LengthAwarePaginator
	{
		return match ($filter) {
			'active' => $this->repository->paginateActive($providerId, $perPage, $page),
			'on_hold' => $this->repository->paginateOnHold($providerId, $perPage, $page),
			'completed' => $this->repository->paginateCompleted($providerId, $perPage, $page),
			'cancelled' => $this->repository->paginateCancelled($providerId, $perPage, $page),
			default => $this->repository->paginateAll($providerId, $perPage, $page),
		};
	}
}
