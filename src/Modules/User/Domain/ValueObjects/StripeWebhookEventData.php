<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * A verified, parsed Stripe webhook delivery — what
 * {@see \PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider::constructWebhookEvent()}
 * hands back once the signature has checked out. `$object` is Stripe's own
 * `data.object` as a plain array (already `->toArray()`'d by the adapter) —
 * every handler in `Application\UseCases\Billing` reads it by array key
 * rather than depending on `\Stripe\*` SDK classes, which is what keeps this
 * an inbound-adapter concern instead of leaking into the Application layer.
 */
final class StripeWebhookEventData
{
    /**
     * @param  array<string, mixed>  $object
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $object,
    ) {
    }
}
