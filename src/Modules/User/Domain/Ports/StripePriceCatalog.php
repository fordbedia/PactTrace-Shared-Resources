<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Ports;

use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\UnmappedStripePriceException;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\BillingInterval;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\StripePriceMapping;

/**
 * The one place a {@see Plan} + {@see BillingInterval} maps to a Stripe
 * Price id, and back. Checkout (Part 1), the webhook's authoritative sync
 * (Part 2), and the change-plan endpoint (Part 4) all resolve through this
 * single port rather than each keeping its own switch statement — see the
 * Stripe billing pass's own instruction: "one small mapping function, not a
 * switch statement scattered across call sites."
 *
 * Implemented by Infrastructure\Stripe\ConfigStripePriceCatalog, reading
 * `config('services.stripe.prices')`.
 */
interface StripePriceCatalog
{
    /**
     * @throws UnmappedStripePriceException when no price id is configured
     *                                       for this plan/interval pair
     */
    public function priceIdFor(Plan $plan, BillingInterval $interval): string;

    /**
     * The reverse lookup — which plan/interval a Stripe Price id represents.
     * Null when the id doesn't match anything in the configured matrix (a
     * price created outside this catalogue, or a stale env value).
     */
    public function resolve(string $priceId): ?StripePriceMapping;
}
