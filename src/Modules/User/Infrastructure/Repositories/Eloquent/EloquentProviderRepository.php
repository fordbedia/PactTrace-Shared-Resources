<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent;

use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\SubdomainAvailability;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Subdomain;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\BaseRepository;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * Serves two ports against the same table: the application's write port and
 * the domain's read-only availability check. Callers depend on whichever of
 * the two describes what they actually need.
 */
class EloquentProviderRepository extends BaseRepository implements ProviderRepository, SubdomainAvailability
{
	public function makeModel(): string
	{
		return Provider::class;
	}

	public function create(array $data): Provider
	{
		return $this->model->create($data);
	}

	public function isTaken(Subdomain $subdomain): bool
	{
		return $this->isExists('subdomain', $subdomain->value);
	}
}
