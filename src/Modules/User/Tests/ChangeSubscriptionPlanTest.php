<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\ChangeSubscriptionPlan;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\NoStripeCustomerException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\PlanChangeBlockedException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanChangeOutcome;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Stripe\FakeBillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The only path besides Checkout that changes a subscription's plan.
 * Exercised against the use case directly (no HTTP); `FakeBillingProvider`
 * records the Stripe price-swap call. The webhook, not this class, writes
 * `subscriptions.plan`. See .claude/rules/plan.md, "Downgrade / over-limit
 * policy" and "Change-plan confirmation modal + proration estimate".
 */
class ChangeSubscriptionPlanTest extends BaseTest
{
    private FakeBillingProvider $stripe;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeBillingProvider;
        $this->app->bind(BillingProvider::class, fn () => $this->stripe);

        $this->tenant = ProviderTenantScenario::make('change-plan-usecase');
        $this->tenant['staff']->forceFill(['status' => 'deactivated'])->save();

        config([
            'services.stripe.prices.starter.monthly' => 'price_starter_monthly',
            'services.stripe.prices.professional.monthly' => 'price_professional_monthly',
            'services.stripe.prices.firm.monthly' => 'price_firm_monthly',
            'services.stripe.prices.firm.yearly' => 'price_firm_yearly',
            'services.stripe.prices.professional.yearly' => 'price_professional_yearly',
        ]);
    }

    private function useCase(): ChangeSubscriptionPlan
    {
        return $this->app->make(ChangeSubscriptionPlan::class);
    }

    private function givenActiveSubscription(string $plan, string $priceId): void
    {
        Subscription::query()->where('provider_id', $this->tenant['provider']->id)->update([
            'stripe_customer_id' => 'cus_change',
            'stripe_subscription_id' => 'sub_change',
            'stripe_price_id' => $priceId,
            'plan' => $plan,
            'status' => 'active',
        ]);
    }

    public function test_an_upgrade_swaps_the_price_immediately_with_prorations(): void
    {
        $this->givenActiveSubscription('professional', 'price_professional_monthly');

        $outcome = $this->useCase()->handle($this->tenant['owner'], Plan::Firm);

        $this->assertSame(PlanChangeOutcome::IMMEDIATE, $outcome->status);
        $this->assertSame(Plan::Firm, $outcome->targetPlan);

        $this->assertCount(1, $this->stripe->subscriptionUpdates);
        $this->assertSame('sub_change', $this->stripe->subscriptionUpdates[0]['subscriptionId']);
        $this->assertSame('price_firm_monthly', $this->stripe->subscriptionUpdates[0]['newPriceId']);
        $this->assertSame('create_prorations', $this->stripe->subscriptionUpdates[0]['prorationBehavior']);
    }

    public function test_a_downgrade_is_also_applied_immediately_not_deferred(): void
    {
        $this->givenActiveSubscription('firm', 'price_firm_monthly');

        $outcome = $this->useCase()->handle($this->tenant['owner'], Plan::Starter);

        $this->assertSame('immediate', $outcome->status);
        $this->assertSame('price_starter_monthly', $this->stripe->subscriptionUpdates[0]['newPriceId']);
        $this->assertSame('create_prorations', $this->stripe->subscriptionUpdates[0]['prorationBehavior']);
    }

    public function test_selecting_the_current_tier_is_a_noop_that_never_calls_stripe(): void
    {
        $this->givenActiveSubscription('firm', 'price_firm_monthly');

        $outcome = $this->useCase()->handle($this->tenant['owner'], Plan::Firm);

        $this->assertSame(PlanChangeOutcome::NOOP, $outcome->status);
        $this->assertCount(0, $this->stripe->subscriptionUpdates);
    }

    public function test_the_current_billing_interval_is_preserved_on_the_target_price(): void
    {
        $this->givenActiveSubscription('professional', 'price_professional_yearly');

        $this->useCase()->handle($this->tenant['owner'], Plan::Firm);

        $this->assertSame('price_firm_yearly', $this->stripe->subscriptionUpdates[0]['newPriceId']);
    }

    public function test_it_does_not_write_the_subscriptions_plan_column_itself(): void
    {
        $this->givenActiveSubscription('professional', 'price_professional_monthly');

        $this->useCase()->handle($this->tenant['owner'], Plan::Firm);

        // The webhook is the only writer — the local row is untouched here.
        $this->assertSame(
            'professional',
            Subscription::query()->where('provider_id', $this->tenant['provider']->id)->value('plan'),
        );
    }

    public function test_it_throws_when_there_is_no_live_stripe_subscription(): void
    {
        Subscription::query()->where('provider_id', $this->tenant['provider']->id)
            ->update(['stripe_subscription_id' => null]);

        $this->expectException(NoStripeCustomerException::class);

        $this->useCase()->handle($this->tenant['owner'], Plan::Firm);
    }

    public function test_it_throws_and_calls_nothing_when_usage_exceeds_the_target(): void
    {
        $this->givenActiveSubscription('firm', 'price_firm_monthly');

        User::factory()->count(5)->create(['provider_id' => $this->tenant['provider']->id])
            ->each(fn (User $u) => $u->assignRole('staff'));

        try {
            $this->useCase()->handle($this->tenant['owner'], Plan::Professional);
            $this->fail('Expected PlanChangeBlockedException.');
        } catch (PlanChangeBlockedException $e) {
            $this->assertFalse($e->result->allowed);
            $this->assertCount(0, $this->stripe->subscriptionUpdates);
        }
    }
}
