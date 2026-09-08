<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;

/**
 * The outcome of
 * {@see \PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\ReconcileCheckoutSession}:
 * whether the browser landing back on `/checkout/success` can be shown a
 * confirmed subscription yet, and the details the success screen renders.
 *
 * `$state`:
 *  - `confirmed` — the local `Subscription` reflects a completed, active checkout
 *  - `pending`   — Stripe has not (yet) recorded this session as paid; the
 *                  webhook has nothing to deliver either. The frontend retries.
 *  - `failed`    — the session id did not resolve, or it belongs to another
 *                  provider. Distinct from a *canceled* checkout (the frontend
 *                  knows that from its own `?canceled=` query param, never from here).
 */
final class CheckoutReconciliation
{
    public function __construct(
        public readonly string $state,
        public readonly ?Subscription $subscription = null,
        public readonly ?int $amountTotalCents = null,
        public readonly ?string $currency = null,
        public readonly ?string $cardBrand = null,
        public readonly ?string $cardLast4 = null,
    ) {
    }

    public static function confirmed(Subscription $subscription, ?CheckoutSessionStatus $remote = null): self
    {
        return new self(
            state: 'confirmed',
            subscription: $subscription,
            amountTotalCents: $remote?->amountTotalCents,
            currency: $remote?->currency,
            cardBrand: $remote?->cardBrand,
            cardLast4: $remote?->cardLast4,
        );
    }

    public static function pending(?Subscription $subscription): self
    {
        return new self(state: 'pending', subscription: $subscription);
    }

    public static function failed(?Subscription $subscription): self
    {
        return new self(state: 'failed', subscription: $subscription);
    }

    /** e.g. "$79.00", or "79.00 EUR" for a non-USD currency, or null when unknown. */
    public function amountLabel(): ?string
    {
        if ($this->amountTotalCents === null) {
            return null;
        }

        $amount = number_format($this->amountTotalCents / 100, 2);
        $currency = strtoupper((string) $this->currency);

        return $currency === 'USD' || $currency === ''
            ? '$' . $amount
            : $amount . ' ' . $currency;
    }

    /** e.g. "Visa •••• 4242", or null when the payment method isn't known. */
    public function cardLabel(): ?string
    {
        if ($this->cardLast4 === null || $this->cardLast4 === '') {
            return null;
        }

        $brand = $this->cardBrand !== null && $this->cardBrand !== ''
            ? ucfirst($this->cardBrand)
            : 'Card';

        return $brand . ' •••• ' . $this->cardLast4;
    }
}
