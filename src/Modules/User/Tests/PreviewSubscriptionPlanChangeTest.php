<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\PreviewSubscriptionPlanChange;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\NoStripeCustomerException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\PlanChangeBlockedException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanChangePreview;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Stripe\FakeBillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The read-only companion to {@see \PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\ChangeSubscriptionPlan}.
 * Exercised against the use case directly (no HTTP) — `FakeBillingProvider`
 * stands in for the live Stripe `invoices/create_preview` read, same
 * convention as ReconcileCheckoutSessionTest. See .claude/rules/plan.md,
 * "Change-plan confirmation modal + proration estimate".
 */
class PreviewSubscriptionPlanChangeTest extends BaseTest
{
    private FakeBillingProvider $stripe;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeBillingProvider;
        $this->app->bind(BillingProvider::class, fn () => $this->stripe);

        $this->tenant = ProviderTenantScenario::make('preview-plan-change');

        // The fixture seeds one staff member (1 seat); Professional/Starter's
        // 1-seat cap would otherwise block a downgrade preview on `seats`.
        $this->tenant['staff']->forceFill(['status' => 'deactivated'])->save();

        config([
            'services.stripe.prices.starter.monthly' => 'price_starter_monthly',
            'services.stripe.prices.professional.monthly' => 'price_professional_monthly',
            'services.stripe.prices.firm.monthly' => 'price_firm_monthly',
            'services.stripe.prices.professional.yearly' => 'price_professional_yearly',
            'services.stripe.prices.firm.yearly' => 'price_firm_yearly',
        ]);
    }

    private function useCase(): PreviewSubscriptionPlanChange
    {
        return $this->app->make(PreviewSubscriptionPlanChange::class);
    }

    private function givenActiveSubscription(string $plan, string $priceId): void
    {
        Subscription::query()->where('provider_id', $this->tenant['provider']->id)->update([
            'stripe_customer_id' => 'cus_preview',
            'stripe_subscription_id' => 'sub_preview',
            'stripe_price_id' => $priceId,
            'plan' => $plan,
            'status' => 'active',
        ]);
    }

    public function test_it_returns_the_providers_estimate_without_touching_the_subscription(): void
    {
        $this->givenActiveSubscription('professional', 'price_professional_monthly');

        $preview = $this->useCase()->handle($this->tenant['owner'], Plan::Firm);

        $this->assertInstanceOf(PlanChangePreview::class, $preview);
        $this->assertCount(1, $this->stripe->planChangePreviews);
        $this->assertSame('sub_preview', $this->stripe->planChangePreviews[0]['subscriptionId']);
        $this->assertSame('price_firm_monthly', $this->stripe->planChangePreviews[0]['newPriceId']);
        // A preview never mutates.
        $this->assertCount(0, $this->stripe->subscriptionUpdates);
    }

    public function test_it_preserves_the_subscriptions_current_billing_interval(): void
    {
        $this->givenActiveSubscription('professional', 'price_professional_yearly');

        $this->useCase()->handle($this->tenant['owner'], Plan::Firm);

        $this->assertSame('price_firm_yearly', $this->stripe->planChangePreviews[0]['newPriceId']);
    }

    public function test_it_throws_when_there_is_no_live_stripe_subscription(): void
    {
        Subscription::query()->where('provider_id', $this->tenant['provider']->id)
            ->update(['stripe_subscription_id' => null]);

        $this->expectException(NoStripeCustomerException::class);

        $this->useCase()->handle($this->tenant['owner'], Plan::Firm);
    }

    public function test_it_throws_when_current_usage_exceeds_the_target_plan(): void
    {
        $this->givenActiveSubscription('firm', 'price_firm_monthly');

        User::factory()->count(5)->create(['provider_id' => $this->tenant['provider']->id])
            ->each(fn (User $u) => $u->assignRole('staff'));

        try {
            $this->useCase()->handle($this->tenant['owner'], Plan::Professional);
            $this->fail('Expected PlanChangeBlockedException.');
        } catch (PlanChangeBlockedException $e) {
            $this->assertFalse($e->result->allowed);
            $this->assertCount(0, $this->stripe->planChangePreviews);
        }
    }
}
