<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\BaseRepository;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;

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

    public function dueForTrialCheck(Carbon $before): Collection
    {
        // status = 'trialing' first (equality) then the trial_ends_at range,
        // matching the (status, trial_ends_at) index added alongside this
        // table — see add_status_trial_index_to_subscriptions_table.
        return $this->model->query()
            ->where('status', 'trialing')
            ->where('trial_ends_at', '<=', $before)
            ->select(['id', 'provider_id', 'plan', 'trial_ends_at'])
            ->get();
    }

    public function markExpired(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->model->query()
            ->whereIn('id', $ids)
            ->where('status', 'trialing') // don't clobber a status a webhook already moved on
            ->update(['status' => 'expired']);
    }
}
