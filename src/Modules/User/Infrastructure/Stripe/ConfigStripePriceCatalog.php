<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Stripe;

use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\UnmappedStripePriceException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\StripePriceCatalog;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\BillingInterval;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\StripePriceMapping;

/**
 * Reads `config('services.stripe.prices')` — a plain
 * `[plan => [interval => price_id]]` matrix (see backend/config/services.php)
 * — for both directions of the Plan/BillingInterval <-> Stripe Price id
 * mapping. The only class that knows the matrix's shape; every caller goes
 * through the {@see StripePriceCatalog} port instead.
 */
final class ConfigStripePriceCatalog implements StripePriceCatalog
{
    /**
     * @param  array<string, array<string, string|null>>  $prices
     */
    public function __construct(
        private readonly array $prices,
    ) {
    }

    public function priceIdFor(Plan $plan, BillingInterval $interval): string
    {
        $priceId = $this->prices[$plan->value][$interval->value] ?? null;

        if ($priceId === null || $priceId === '') {
            throw new UnmappedStripePriceException(
                "No Stripe price is configured for plan \"{$plan->value}\" / interval \"{$interval->value}\"."
            );
        }

        return $priceId;
    }

    public function resolve(string $priceId): ?StripePriceMapping
    {
        foreach ($this->prices as $planValue => $intervals) {
            foreach ($intervals as $intervalValue => $configuredId) {
                if ($configuredId !== null && $configuredId !== '' && $configuredId === $priceId) {
                    $plan = Plan::tryFrom($planValue);
                    $interval = BillingInterval::tryFrom($intervalValue);

                    if ($plan !== null && $interval !== null) {
                        return new StripePriceMapping($plan, $interval);
                    }
                }
            }
        }

        return null;
    }
}
