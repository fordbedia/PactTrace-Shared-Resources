<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * Everything {@see \PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider::createCheckoutSession()}
 * needs — built by `Application\UseCases\Billing\CreateCheckoutSession`, kept
 * framework-free so the adapter is the only place that touches the Stripe SDK
 * shape directly.
 */
final class CheckoutSessionRequest
{
    public function __construct(
        public readonly string $priceId,
        /** Matched back to a Provider by the webhook — see Part 2 of the Stripe billing pass. */
        public readonly string $providerId,
        public readonly string $successUrl,
        public readonly string $cancelUrl,
        /** An existing Stripe customer to reuse, or null to let Stripe create one. */
        public readonly ?string $customerId = null,
        public readonly ?string $customerEmail = null,
    ) {
    }
}
