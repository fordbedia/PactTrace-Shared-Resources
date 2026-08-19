<?php

namespace PactTrackSDK\SharedResources\Modules\Client\Application\Action;

use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Client\Application\DTO\ClientSearchData;
use PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientRepository;

class SearchClientsHandler
{
	public function __construct(private ClientRepository $repository)
	{}

	public function handle(ClientSearchData $data): Collection
	{
		return $this->repository->searchForSelection($data->provider_id, $data->search, $data->limit);
	}
}
