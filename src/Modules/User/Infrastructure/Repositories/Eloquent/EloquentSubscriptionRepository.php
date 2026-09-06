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
            // stripe_subscription_id is selected (not just the four columns
            // ProcessTrialExpirations used to need) so it can scope its
            // "ending soon" warning bucket to card-less trials only — a
            // trial that went through Stripe Checkout has its own
            // `customer.subscription.trial_will_end` webhook for that (see
            // Application\UseCases\Billing\HandleTrialWillEnd and
            // .claude/rules/user.md). The hard-expiry bucket is unaffected —
            // it still applies to every trialing row regardless.
            ->select(['id', 'provider_id', 'plan', 'trial_ends_at', 'stripe_subscription_id'])
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

    public function findByProviderId(int $providerId): ?Subscription
    {
        return $this->model->query()->where('provider_id', $providerId)->first();
    }

    public function findByStripeSubscriptionId(string $stripeSubscriptionId): ?Subscription
    {
        return $this->model->query()->where('stripe_subscription_id', $stripeSubscriptionId)->first();
    }

    public function findByStripeCustomerId(string $stripeCustomerId): ?Subscription
    {
        return $this->model->query()->where('stripe_customer_id', $stripeCustomerId)->first();
    }

    public function findByStripeIdentifiers(?string $stripeSubscriptionId, ?string $stripeCustomerId): ?Subscription
    {
        if ($stripeSubscriptionId !== null && $stripeSubscriptionId !== '') {
            $subscription = $this->findByStripeSubscriptionId($stripeSubscriptionId);
            if ($subscription !== null) {
                return $subscription;
            }
        }

        return ($stripeCustomerId !== null && $stripeCustomerId !== '')
            ? $this->findByStripeCustomerId($stripeCustomerId)
            : null;
    }

    public function save(Subscription $subscription): Subscription
    {
        $subscription->save();

        return $subscription;
    }
}
