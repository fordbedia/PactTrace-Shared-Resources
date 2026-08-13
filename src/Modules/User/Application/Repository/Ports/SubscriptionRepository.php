<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\User\Application\Repository\Ports;

use PactTraceSDK\SharedResources\Modules\User\Models\Subscription;

/**
 * Port for persisting a provider's billing record.
 *
 * Implemented by Infrastructure\Repositories\Eloquent\EloquentSubscriptionRepository.
 * Deliberately as narrow as ProviderRepository — the Stripe webhook handlers
 * that will update status/stripe_* fields are a separate future concern and
 * can widen this port when they land.
 */
interface SubscriptionRepository
{
    public function create(array $data): Subscription;
}
