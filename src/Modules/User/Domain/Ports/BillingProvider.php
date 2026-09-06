<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Ports;

use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\InvalidStripeWebhookSignatureException;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSession;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSessionRequest;
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
     * @return string the hosted Billing Portal URL to redirect to
     */
    public function createBillingPortalSession(string $customerId, string $returnUrl): string;

    /**
     * Changes which Price a live subscription is billed against. Does not
     * write `subscriptions.plan` itself — the resulting
     * `customer.subscription.updated` webhook is the only writer of that
     * column, keeping that invariant intact even for a change this backend
     * itself initiated. See .claude/rules/plan.md.
     */
    public function updateSubscriptionPrice(string $subscriptionId, string $newPriceId, string $prorationBehavior): void;

    /**
     * Verifies `$signature` against `$payload` using `$webhookSecret` and
     * hands back the parsed event.
     *
     * @throws InvalidStripeWebhookSignatureException on a bad/missing signature
     */
    public function constructWebhookEvent(string $payload, string $signature, string $webhookSecret): StripeWebhookEventData;
}
