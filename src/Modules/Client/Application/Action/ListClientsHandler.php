<?php

namespace PactTrackSDK\SharedResources\Modules\Client\Application\Action;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use PactTrackSDK\SharedResources\Modules\Client\Application\DTO\ClientListData;
use PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Service\ClientListingService;

class ListClientsHandler
{
	public function __construct(private ClientListingService $service)
	{}

	public function handle(ClientListData $data): LengthAwarePaginator
	{
		return $this->service->paginate($data->provider_id, $data->filter, $data->per_page, $data->page);
	}
}
