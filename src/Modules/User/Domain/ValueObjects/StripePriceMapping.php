<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * The reverse of {@see \PactTrackSDK\SharedResources\Modules\User\Domain\Ports\StripePriceCatalog}'s
 * forward lookup — which {@see Plan} and {@see BillingInterval} a given
 * Stripe Price id represents. Built once, in `ConfigStripePriceCatalog`, from
 * the same `config('services.stripe.prices')` matrix the forward lookup
 * reads, so the two directions can never disagree.
 */
final class StripePriceMapping
{
    public function __construct(
        public readonly Plan $plan,
        public readonly BillingInterval $interval,
    ) {
    }
}
