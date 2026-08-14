<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Infrastructure\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use PactTraceSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientRepository;
use PactTraceSDK\SharedResources\Modules\Client\Application\Ports\Service\ClientListingService as ClientListingServiceContract;

class ClientListingService implements ClientListingServiceContract
{
	public function __construct(private ClientRepository $repository)
	{}

	public function paginate(int $providerId, string $filter, int $perPage, ?int $page): LengthAwarePaginator
	{
		return match ($filter) {
			'active' => $this->repository->paginateActive($providerId, $perPage, $page),
			'invited' => $this->repository->paginateInvited($providerId, $perPage, $page),
			'archived' => $this->repository->paginateArchived($providerId, $perPage, $page),
			default => $this->repository->paginateAll($providerId, $perPage, $page),
		};
	}
}
