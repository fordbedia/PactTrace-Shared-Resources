<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Application\Action;

use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\NotificationPreferenceRepository;

class KeyToIdResolver
{
	public function __construct(private readonly NotificationPreferenceRepository $repository)
	{}

	public function __invoke(string $key)
	{
		return $this->IdByKey($key);
	}

	public function IdByKey(string $key)
	{
		return $this->repository->findIdByKey($key);
	}
}