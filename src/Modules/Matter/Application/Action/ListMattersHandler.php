<?php

namespace PactTraceSDK\SharedResources\Modules\Matter\Application\Action;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use PactTraceSDK\SharedResources\Modules\Matter\Application\DTO\MattersListData;
use PactTraceSDK\SharedResources\Modules\Matter\Application\Ports\Service\MattersListingService;

class ListMattersHandler
{
	public function __construct(private MattersListingService $service)
	{}

	public function handle(MattersListData $data): LengthAwarePaginator
	{
		return $this->service->paginate($data->provider_id, $data->filter, $data->per_page, $data->page);
	}
}
