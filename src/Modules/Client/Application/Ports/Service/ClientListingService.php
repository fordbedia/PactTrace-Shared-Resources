<?php

namespace PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Service;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ClientListingService
{
	public function paginate(int $providerId, string $filter, int $perPage, ?int $page): LengthAwarePaginator;
}
