<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Service;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MattersListingService
{
	public function paginate(int $providerId, string $filter, int $perPage, ?int $page, ?int $clientId = null): LengthAwarePaginator;
}
