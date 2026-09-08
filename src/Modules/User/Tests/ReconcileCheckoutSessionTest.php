<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\ReconcileCheckoutSession;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSessionStatus;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Stripe\FakeBillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The reconciliation fallback behind `/checkout/success`. Exercised against
 * the use case directly (no HTTP) — `FakeBillingProvider` stands in for the
 * live Stripe read, same convention as StripeWebhookControllerTest.
 */
class ReconcileCheckoutSessionTest extends BaseTest
{
    private FakeBillingProvider $stripe;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeBillingProvider();
        $this->app->bind(BillingProvider::class, fn () => $this->stripe);

        $this->tenant = ProviderTenantScenario::make('reconcile-checkout');

        config(['services.stripe.prices.professional.monthly' => 'price_professional_monthly']);
    }

    private function reconcile(): ReconcileCheckoutSession
    {
        return $this->app->make(ReconcileCheckoutSession::class);
    }

    private function providerId(): int
    {
        return (int) $this->tenant['provider']->id;
    }

    private function subscription(): Subscription
    {
        return Subscription::query()->where('provider_id', $this->providerId())->firstOrFail();
    }

    /** A fully-formed "checkout completed" answer from Stripe. */
    private function completedSessionStatus(array $subscriptionOverrides = []): CheckoutSessionStatus
    {
        return new CheckoutSessionStatus(
            found: true,
            paymentStatus: 'paid',
            customerId: 'cus_reconcile',
            clientReferenceId: (string) $this->providerId(),
            subscriptionObject: array_merge([
                'id' => 'sub_reconcile',
                'customer' => 'cus_reconcile',
                'status' => 'active',
                // Item-level `current_period_end`, matching Stripe's current
                // ("basil") API shape — see SyncSubscriptionFromStripe.
                'items' => ['data' => [[
                    'current_period_end' => now()->addDays(30)->timestamp,
                    'price' => ['id' => 'price_professional_monthly'],
                ]]],
            ], $subscriptionOverrides),
            amountTotalCents: 7900,
            currency: 'usd',
            cardBrand: 'visa',
            cardLast4: '4242',
        );
    }

    public function test_a_synced_subscription_is_confirmed_without_calling_stripe(): void
    {
        $this->subscription()->forceFill([
            'status' => 'active',
            'stripe_subscription_id' => 'sub_live',
            'current_period_ends_at' => now()->addDays(20),
        ])->save();

        $result = $this->reconcile()->handle($this->providerId(), 'cs_test_123');

        $this->assertSame('confirmed', $result->state);
        $this->assertSame([], $this->stripe->retrievedCheckoutSessions, 'The already-synced fast path must not hit Stripe.');
    }

    public function test_a_paid_session_is_reconciled_when_the_webhook_has_not_landed(): void
    {
        $this->subscription()->forceFill([
            'status' => 'trialing',
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
            'current_period_ends_at' => null,
        ])->save();

        $this->stripe->nextCheckoutSessionStatus = $this->completedSessionStatus();

        $result = $this->reconcile()->handle($this->providerId(), 'cs_test_paid');

        $this->assertSame('confirmed', $result->state);
        $this->assertSame(['cs_test_paid'], $this->stripe->retrievedCheckoutSessions);
        $this->assertSame('$79.00', $result->amountLabel());
        $this->assertSame('Visa •••• 4242', $result->cardLabel());

        $subscription = $this->subscription();
        $this->assertSame('cus_reconcile', $subscription->stripe_customer_id);
        $this->assertSame('sub_reconcile', $subscription->stripe_subscription_id);
        $this->assertSame('professional', $subscription->plan);
        $this->assertSame('active', $subscription->status);
        $this->assertNotNull($subscription->current_period_ends_at, 'current_period_ends_at must be synced from the item-level field');
        $this->assertTrue($subscription->current_period_ends_at->isFuture());
        $this->assertSame('professional', $this->tenant['provider']->refresh()->plan);
    }

    public function test_an_unpaid_session_is_pending_and_writes_nothing(): void
    {
        $this->stripe->nextCheckoutSessionStatus = new CheckoutSessionStatus(
            found: true,
            paymentStatus: 'unpaid',
            clientReferenceId: (string) $this->providerId(),
        );

        $result = $this->reconcile()->handle($this->providerId(), 'cs_test_unpaid');

        $this->assertSame('pending', $result->state);
        $this->assertNull($this->subscription()->stripe_subscription_id);
    }

    public function test_an_unresolved_session_is_failed(): void
    {
        // FakeBillingProvider returns CheckoutSessionStatus::notFound() by default.
        $result = $this->reconcile()->handle($this->providerId(), 'cs_test_garbage');

        $this->assertSame('failed', $result->state);
    }

    public function test_a_session_belonging_to_another_provider_is_failed_and_writes_nothing(): void
    {
        $this->stripe->nextCheckoutSessionStatus = new CheckoutSessionStatus(
            found: true,
            paymentStatus: 'paid',
            customerId: 'cus_other',
            clientReferenceId: (string) ($this->providerId() + 999),
            subscriptionObject: ['id' => 'sub_other', 'status' => 'active'],
        );

        $result = $this->reconcile()->handle($this->providerId(), 'cs_test_other');

        $this->assertSame('failed', $result->state);
        $this->assertNull($this->subscription()->stripe_subscription_id);
    }

    public function test_reconciling_twice_is_idempotent_and_sends_no_mail(): void
    {
        Mail::fake();

        $this->subscription()->forceFill([
            'status' => 'trialing',
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
            'current_period_ends_at' => null,
        ])->save();

        $this->stripe->nextCheckoutSessionStatus = $this->completedSessionStatus();

        $first = $this->reconcile()->handle($this->providerId(), 'cs_test_twice');
        $second = $this->reconcile()->handle($this->providerId(), 'cs_test_twice');

        $this->assertSame('confirmed', $first->state);
        $this->assertSame('confirmed', $second->state);
        $this->assertSame(1, Subscription::query()->where('provider_id', $this->providerId())->count());
        $this->assertSame('sub_reconcile', $this->subscription()->stripe_subscription_id);
        Mail::assertNothingOutgoing();
    }
}
