<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\User\Application\Repository\Ports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PactTraceSDK\SharedResources\Modules\User\Models\Subscription;

/**
 * Port for persisting and querying a provider's billing record.
 *
 * Implemented by Infrastructure\Repositories\Eloquent\EloquentSubscriptionRepository.
 * Widened once, by ProcessTrialExpirations' scheduled scan — widen again for
 * the Stripe webhook handlers when they land rather than reaching for
 * Subscription::query() outside this port.
 */
interface SubscriptionRepository
{
    public function create(array $data): Subscription;

    /**
     * Still-trialing subscriptions whose trial ends at or before $before —
     * i.e. already expired, or expiring within the caller's warning window.
     * Only `id`/`provider_id`/`plan`/`trial_ends_at` are selected: this feeds
     * a daily scheduled scan, not a UI list, and has no business loading
     * stripe_* columns nobody reads here.
     */
    public function dueForTrialCheck(Carbon $before): Collection;

    /**
     * Bulk-flips still-trialing subscriptions to `expired` in one statement.
     * Callers are responsible for having already decided (via
     * dueForTrialCheck's trial_ends_at) which ids actually qualify — this
     * method trusts the list rather than re-deriving it.
     *
     * @param  list<int>  $ids
     */
    public function markExpired(array $ids): int;
}
