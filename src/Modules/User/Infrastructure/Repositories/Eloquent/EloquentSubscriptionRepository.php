<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent;

use PactTraceSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTraceSDK\SharedResources\Modules\User\Infrastructure\Repositories\BaseRepository;
use PactTraceSDK\SharedResources\Modules\User\Models\Subscription;

class EloquentSubscriptionRepository extends BaseRepository implements SubscriptionRepository
{
    public function makeModel(): string
    {
        return Subscription::class;
    }

    public function create(array $data): Subscription
    {
        return $this->model->create($data);
    }
}
