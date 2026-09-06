<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * The hosted Stripe Checkout URL the frontend redirects the browser to.
 * Nothing else about the session is needed on this side — Stripe reports
 * everything else back through the webhook (Part 2).
 */
final class CheckoutSession
{
    public function __construct(
        public readonly string $url,
    ) {
    }
}
