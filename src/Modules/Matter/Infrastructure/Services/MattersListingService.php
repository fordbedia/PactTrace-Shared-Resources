<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Service\MattersListingService as MattersListingServiceContract;

class MattersListingService implements MattersListingServiceContract
{
	public function __construct(private MattersRepository $repository)
	{}

	public function paginate(int $providerId, string $filter, int $perPage, ?int $page, ?int $clientId = null, bool $archived = false, ?string $sort = null, string $direction = 'asc'): LengthAwarePaginator
	{
		return match ($filter) {
			'active' => $this->repository->paginateActive($providerId, $perPage, $page, $clientId, $archived, $sort, $direction),
			'on_hold' => $this->repository->paginateOnHold($providerId, $perPage, $page, $clientId, $archived, $sort, $direction),
			'completed' => $this->repository->paginateCompleted($providerId, $perPage, $page, $clientId, $archived, $sort, $direction),
			'cancelled' => $this->repository->paginateCancelled($providerId, $perPage, $page, $clientId, $archived, $sort, $direction),
			default => $this->repository->paginateAll($providerId, $perPage, $page, $clientId, $archived, $sort, $direction),
		};
	}
}
