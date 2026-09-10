<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Ports;

use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\InvalidStripeWebhookSignatureException;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSession;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSessionRequest;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSessionStatus;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanChangePreview;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\StripeWebhookEventData;

/**
 * The seam to Stripe's own SDK — every `Application\UseCases\Billing\*` use
 * case depends on this, never on `\Stripe\*` classes directly, per the
 * hexagonal rule in the top-level CLAUDE.md. Implemented by
 * Infrastructure\Stripe\StripeBillingProvider (the real `stripe-php` client)
 * and Infrastructure\Stripe\FakeBillingProvider (no network calls — bound in
 * tests, same "fake over mock" convention as Signature's FakeSignatureProvider).
 */
interface BillingProvider
{
    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession;

    /**
     * Reads a Checkout Session straight from Stripe (never from local state) —
     * the reconciliation fallback for a browser that lands back on
     * `/checkout/success` before, or without, the webhook having been
     * processed. Expands `subscription` (and its payment method) so
     * subscription status/price and the card come back in one round trip.
     * Returns {@see CheckoutSessionStatus::notFound()} — never throws — when
     * the id doesn't resolve.
     */
    public function retrieveCheckoutSession(string $sessionId): CheckoutSessionStatus;

    /**
     * `$configurationId` selects which Stripe Billing Portal *configuration*
     * the session renders with — the permissive one (all plan switches) or
     * the downgrade-locked one, chosen per session by the Application layer's
     * ResolvePortalConfiguration service.
     * Null lets the adapter fall back to its configured default
     * (`services.stripe.billing_portal_configuration_id`), preserving the
     * original behaviour for any caller that doesn't resolve one.
     *
     * @return string the hosted Billing Portal URL to redirect to
     */
    public function createBillingPortalSession(
        string $customerId,
        string $returnUrl,
        ?string $configurationId = null,
    ): string;

    /**
     * Changes which Price a live subscription is billed against. Does not
     * write `subscriptions.plan` itself — the resulting
     * `customer.subscription.updated` webhook is the only writer of that
     * column, keeping that invariant intact even for a change this backend
     * itself initiated. See .claude/rules/plan.md.
     */
    public function updateSubscriptionPrice(string $subscriptionId, string $newPriceId, string $prorationBehavior): void;

    /**
     * A **non-mutating** estimate of what switching to `$newPriceId` would
     * cost — Stripe's `invoices/create_preview` with `create_prorations`,
     * i.e. the upcoming invoice including the proration charge/credit (the
     * figure the Stripe portal shows as "next estimated payment"). Never
     * changes the subscription.
     */
    public function previewPlanChange(string $subscriptionId, string $newPriceId): PlanChangePreview;

    /**
     * Verifies `$signature` against `$payload` using `$webhookSecret` and
     * hands back the parsed event.
     *
     * @throws InvalidStripeWebhookSignatureException on a bad/missing signature
     */
    public function constructWebhookEvent(string $payload, string $signature, string $webhookSecret): StripeWebhookEventData;
}
