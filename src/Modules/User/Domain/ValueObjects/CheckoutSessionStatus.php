<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * A Stripe Checkout Session read back *directly from Stripe* — the
 * reconciliation fallback's only reason to exist (see
 * {@see \PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\ReconcileCheckoutSession}).
 *
 * Deliberately its own shape rather than reusing {@see StripeWebhookEventData}:
 * that VO models a webhook *event*, this one models a session *lookup*, and
 * overloading one for the other would blur the seam. `$subscriptionObject` is
 * Stripe's expanded Subscription as a plain array (already `->toArray()`'d by
 * the adapter) so it can be handed straight to
 * `SyncSubscriptionFromStripe::handle()` without the Application layer ever
 * touching a `\Stripe\*` class.
 */
final class CheckoutSessionStatus
{
    /**
     * @param  array<string, mixed>  $subscriptionObject  Stripe Subscription (expanded) as an array, or [] when the session has none yet
     */
    public function __construct(
        /** false = the session id did not resolve at Stripe at all */
        public readonly bool $found,
        /** Stripe's `payment_status`: `paid` | `unpaid` | `no_payment_required` | '' when not found */
        public readonly string $paymentStatus,
        public readonly ?string $customerId = null,
        /** The `client_reference_id` set at Checkout — the provider id, as a string. */
        public readonly ?string $clientReferenceId = null,
        public readonly array $subscriptionObject = [],
        /** Session `amount_total` in the currency's minor unit (cents), when Stripe reports it. */
        public readonly ?int $amountTotalCents = null,
        public readonly ?string $currency = null,
        public readonly ?string $cardBrand = null,
        public readonly ?string $cardLast4 = null,
    ) {
    }

    public static function notFound(): self
    {
        return new self(found: false, paymentStatus: '');
    }

    public function subscriptionId(): ?string
    {
        $id = $this->subscriptionObject['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function subscriptionStatus(): ?string
    {
        $status = $this->subscriptionObject['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * A completed checkout: Stripe resolved the session and it is paid (or
     * needed no payment), and a subscription actually exists on it.
     */
    public function isCompleted(): bool
    {
        return $this->found
            && in_array($this->paymentStatus, ['paid', 'no_payment_required'], true)
            && $this->subscriptionId() !== null;
    }
}
