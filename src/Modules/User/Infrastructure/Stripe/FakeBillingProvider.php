<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Stripe;

use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\InvalidStripeWebhookSignatureException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSession;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSessionRequest;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSessionStatus;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanChangePreview;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\StripeWebhookEventData;

/**
 * No network calls, no credentials — bound in tests exactly like Signature's
 * `FakeSignatureProvider` (see .claude/rules/signature.md). Records every
 * call it receives so a test can assert on it (e.g. "the change-plan
 * endpoint really did call Stripe's update API") without hitting the real
 * Stripe API.
 */
final class FakeBillingProvider implements BillingProvider
{
    /** @var list<array{subscriptionId: string, newPriceId: string, prorationBehavior: string}> */
    public array $subscriptionUpdates = [];

    /** @var list<array{subscriptionId: string, newPriceId: string}> — every {@see previewPlanChange()} call. */
    public array $planChangePreviews = [];

    /** Canned answer for {@see previewPlanChange()}; falls back to a plausible default. */
    public ?PlanChangePreview $nextPlanChangePreview = null;

    /** @var list<CheckoutSessionRequest> */
    public array $checkoutSessions = [];

    public string $checkoutUrl = 'https://checkout.stripe.com/c/pay/fake';

    public string $portalUrl = 'https://billing.stripe.com/p/session/fake';

    /** Every configuration id {@see createBillingPortalSession()} was handed, in order (null = adapter default). */
    public array $portalSessionConfigurationIds = [];

    /** Set by a test to make {@see constructWebhookEvent()} throw. */
    public bool $rejectSignature = false;

    /** Set by a test to control what {@see constructWebhookEvent()} returns. */
    public ?StripeWebhookEventData $nextEvent = null;

    /** Every session id {@see retrieveCheckoutSession()} was asked about, in order. */
    public array $retrievedCheckoutSessions = [];

    /** Per-session-id canned answers for {@see retrieveCheckoutSession()}. */
    public array $checkoutSessionStatusById = [];

    /** Fallback answer for {@see retrieveCheckoutSession()} when the id has no entry above. */
    public ?CheckoutSessionStatus $nextCheckoutSessionStatus = null;

    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        $this->checkoutSessions[] = $request;

        return new CheckoutSession(url: $this->checkoutUrl);
    }

    public function retrieveCheckoutSession(string $sessionId): CheckoutSessionStatus
    {
        $this->retrievedCheckoutSessions[] = $sessionId;

        return $this->checkoutSessionStatusById[$sessionId]
            ?? $this->nextCheckoutSessionStatus
            ?? CheckoutSessionStatus::notFound();
    }

    public function createBillingPortalSession(
        string $customerId,
        string $returnUrl,
        ?string $configurationId = null,
    ): string {
        $this->portalSessionConfigurationIds[] = $configurationId;

        return $this->portalUrl;
    }

    public function updateSubscriptionPrice(string $subscriptionId, string $newPriceId, string $prorationBehavior): void
    {
        $this->subscriptionUpdates[] = [
            'subscriptionId' => $subscriptionId,
            'newPriceId' => $newPriceId,
            'prorationBehavior' => $prorationBehavior,
        ];
    }

    public function previewPlanChange(string $subscriptionId, string $newPriceId): PlanChangePreview
    {
        $this->planChangePreviews[] = [
            'subscriptionId' => $subscriptionId,
            'newPriceId' => $newPriceId,
        ];

        return $this->nextPlanChangePreview ?? new PlanChangePreview(
            dueTodayCents: 0,
            nextInvoiceTotalCents: 22900,
            nextInvoiceDateIso: '2026-10-10T00:00:00+00:00',
            recurringAmountCents: 14900,
            currency: 'usd',
            lineItems: [
                ['description' => 'Remaining time on Firm - Monthly', 'amount_cents' => 8000],
                ['description' => '1 × Firm - Monthly', 'amount_cents' => 14900],
            ],
        );
    }

    public function constructWebhookEvent(string $payload, string $signature, string $webhookSecret): StripeWebhookEventData
    {
        if ($this->rejectSignature) {
            throw new InvalidStripeWebhookSignatureException('Fake signature rejection.');
        }

        return $this->nextEvent ?? new StripeWebhookEventData(id: 'evt_fake', type: 'unknown', object: []);
    }
}
