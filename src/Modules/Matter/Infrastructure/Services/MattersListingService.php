<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Service\MattersListingService as MattersListingServiceContract;

class MattersListingService implements MattersListingServiceContract
{
	public function __construct(private MattersRepository $repository)
	{}

	public function paginate(int $providerId, string $filter, int $perPage, ?int $page, ?int $clientId = null): LengthAwarePaginator
	{
		return match ($filter) {
			'active' => $this->repository->paginateActive($providerId, $perPage, $page, $clientId),
			'on_hold' => $this->repository->paginateOnHold($providerId, $perPage, $page, $clientId),
			'completed' => $this->repository->paginateCompleted($providerId, $perPage, $page, $clientId),
			'cancelled' => $this->repository->paginateCancelled($providerId, $perPage, $page, $clientId),
			default => $this->repository->paginateAll($providerId, $perPage, $page, $clientId),
		};
	}
}
