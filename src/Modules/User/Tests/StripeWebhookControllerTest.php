<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\GetPlanUsageSummary;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\PlanPolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\GatedAction;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\StripeWebhookEventData;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Stripe\FakeBillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Models\StripeWebhookEvent;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * `POST /api/v1/stripe/webhook` — no auth middleware, so this only needs
 * LoadsModuleApiRoutes (no Sanctum harness). `FakeBillingProvider` stands in
 * for the real Stripe SDK client throughout — see
 * Infrastructure\Stripe\FakeBillingProvider's own docblock.
 */
class StripeWebhookControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private FakeBillingProvider $stripe;

    private TestScenarioCollection $tenant;

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeBillingProvider();
        $this->app->bind(BillingProvider::class, fn () => $this->stripe);

        $this->tenant = ProviderTenantScenario::make('stripe-webhook');
    }

    public function test_a_bad_signature_is_rejected_with_400_and_touches_no_data(): void
    {
        $this->stripe->rejectSignature = true;

        $before = StripeWebhookEvent::query()->count();

        $response = $this->postJson('/api/v1/stripe/webhook', ['type' => 'checkout.session.completed'], [
            'Stripe-Signature' => 'bad',
        ]);

        $response->assertStatus(400);
        $this->assertSame($before, StripeWebhookEvent::query()->count());
    }

    public function test_a_replayed_event_id_is_a_no_op_the_second_time(): void
    {
        $subscription = $this->subscriptionFor($this->tenant, ['stripe_customer_id' => null, 'stripe_subscription_id' => null]);

        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_replay_1',
            type: 'checkout.session.completed',
            object: [
                'client_reference_id' => (string) $this->tenant['provider']->id,
                'customer' => 'cus_replay',
                'subscription' => 'sub_replay',
            ],
        );

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();
        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $this->assertSame(1, StripeWebhookEvent::query()->where('stripe_event_id', 'evt_replay_1')->count());
        $subscription->refresh();
        $this->assertSame('cus_replay', $subscription->stripe_customer_id);
    }

    public function test_checkout_session_completed_links_customer_and_subscription_ids(): void
    {
        $subscription = $this->subscriptionFor($this->tenant, ['stripe_customer_id' => null, 'stripe_subscription_id' => null]);

        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_checkout_1',
            type: 'checkout.session.completed',
            object: [
                'client_reference_id' => (string) $this->tenant['provider']->id,
                'customer' => 'cus_123',
                'subscription' => 'sub_123',
            ],
        );

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $subscription->refresh();
        $this->assertSame('cus_123', $subscription->stripe_customer_id);
        $this->assertSame('sub_123', $subscription->stripe_subscription_id);
        // plan/status are deliberately untouched here — customer.subscription.created
        // is the authoritative source for those.
        $this->assertSame('firm', $subscription->plan);
    }

    public function test_subscription_updated_writes_both_subscription_and_provider_plan(): void
    {
        $subscription = $this->subscriptionFor($this->tenant, [
            'stripe_customer_id' => 'cus_abc',
            'stripe_subscription_id' => 'sub_abc',
            'plan' => 'firm',
            'status' => 'trialing',
        ]);
        $this->tenant['provider']->forceFill(['plan' => 'firm'])->save();

        config(['services.stripe.prices.professional.monthly' => 'price_professional_monthly']);

        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_sub_updated_1',
            type: 'customer.subscription.updated',
            object: [
                'id' => 'sub_abc',
                'customer' => 'cus_abc',
                'status' => 'active',
                'current_period_end' => now()->addDays(30)->timestamp,
                'items' => ['data' => [['price' => ['id' => 'price_professional_monthly']]]],
            ],
        );

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $subscription->refresh();
        $this->tenant['provider']->refresh();

        $this->assertSame('professional', $subscription->plan);
        $this->assertSame('active', $subscription->status);
        $this->assertSame('professional', $this->tenant['provider']->plan);
    }

    public function test_subscription_updated_reads_current_period_end_from_the_item_when_absent_at_the_top_level(): void
    {
        // Stripe API version 2025-03-31 ("basil") moved `current_period_end`
        // off the Subscription and onto each Subscription item.
        $subscription = $this->subscriptionFor($this->tenant, [
            'stripe_customer_id' => 'cus_basil',
            'stripe_subscription_id' => 'sub_basil',
            'status' => 'trialing',
            'current_period_ends_at' => null,
        ]);

        $periodEnd = now()->addDays(30)->timestamp;

        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_sub_basil_1',
            type: 'customer.subscription.updated',
            object: [
                'id' => 'sub_basil',
                'customer' => 'cus_basil',
                'status' => 'active',
                // No top-level `current_period_end`.
                'items' => ['data' => [[
                    'current_period_end' => $periodEnd,
                    'price' => ['id' => 'price_firm_monthly'],
                ]]],
            ],
        );
        config(['services.stripe.prices.firm.monthly' => 'price_firm_monthly']);

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertNotNull($subscription->current_period_ends_at);
        $this->assertSame($periodEnd, $subscription->current_period_ends_at->timestamp);
    }

    public function test_unpaid_status_collapses_into_past_due(): void
    {
        $subscription = $this->subscriptionFor($this->tenant, [
            'stripe_customer_id' => 'cus_unpaid',
            'stripe_subscription_id' => 'sub_unpaid',
        ]);

        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_unpaid_1',
            type: 'customer.subscription.updated',
            object: ['id' => 'sub_unpaid', 'customer' => 'cus_unpaid', 'status' => 'unpaid'],
        );

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $this->assertSame('past_due', $subscription->refresh()->status);
    }

    public function test_subscription_deleted_cancels_the_subscription(): void
    {
        $subscription = $this->subscriptionFor($this->tenant, [
            'stripe_customer_id' => 'cus_del',
            'stripe_subscription_id' => 'sub_del',
            'status' => 'active',
        ]);

        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_del_1',
            type: 'customer.subscription.deleted',
            object: ['id' => 'sub_del', 'customer' => 'cus_del'],
        );

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $subscription->refresh();
        $this->assertSame('canceled', $subscription->status);
        $this->assertNotNull($subscription->canceled_at);
    }

    public function test_payment_failed_writes_an_audit_row_and_then_blocks_a_gated_action(): void
    {
        $this->subscriptionFor($this->tenant, [
            'stripe_customer_id' => 'cus_fail',
            'stripe_subscription_id' => 'sub_fail',
            'status' => 'active',
        ]);

        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_fail_1',
            type: 'invoice.payment_failed',
            object: ['customer' => 'cus_fail', 'subscription' => 'sub_fail'],
        );

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $this->tenant['provider']->id,
            'action' => 'subscription.payment_failed',
        ]);

        // The status column itself is untouched by this event on purpose —
        // simulate the paired customer.subscription.updated Stripe also
        // fires, which is what actually flips status.
        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_status_after_fail',
            type: 'customer.subscription.updated',
            object: ['id' => 'sub_fail', 'customer' => 'cus_fail', 'status' => 'past_due'],
        );
        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $result = app(PlanPolicy::class)->evaluate(
            GatedAction::UploadDocument,
            \PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan::Firm,
            'past_due',
            app(GetPlanUsageSummary::class)->handle((int) $this->tenant['provider']->id),
        );

        $this->assertFalse($result->allowed);
    }

    public function test_invoice_paid_clears_past_due_and_records_recovery(): void
    {
        $subscription = $this->subscriptionFor($this->tenant, [
            'stripe_customer_id' => 'cus_recover',
            'stripe_subscription_id' => 'sub_recover',
            'status' => 'past_due',
        ]);

        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_paid_1',
            type: 'invoice.paid',
            object: ['customer' => 'cus_recover', 'subscription' => 'sub_recover'],
        );

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $subscription->provider_id,
            'action' => 'subscription.payment_recovered',
        ]);
    }

    public function test_invoice_paid_on_an_already_active_subscription_records_nothing(): void
    {
        $subscription = $this->subscriptionFor($this->tenant, [
            'stripe_customer_id' => 'cus_normal',
            'stripe_subscription_id' => 'sub_normal',
            'status' => 'active',
        ]);

        $before = AuditLog::query()->count();

        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_paid_normal',
            type: 'invoice.paid',
            object: ['customer' => 'cus_normal', 'subscription' => 'sub_normal'],
        );

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $this->assertSame($before, AuditLog::query()->count());
    }

    public function test_trial_will_end_records_an_audit_row(): void
    {
        $subscription = $this->subscriptionFor($this->tenant, [
            'stripe_customer_id' => 'cus_trial',
            'stripe_subscription_id' => 'sub_trial',
            'status' => 'trialing',
        ]);

        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_trial_end',
            type: 'customer.subscription.trial_will_end',
            object: ['id' => 'sub_trial', 'customer' => 'cus_trial'],
        );

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $subscription->provider_id,
            'action' => 'subscription.trial_ending_soon',
        ]);
    }

    // ───────────────────── subscription_schedule.* (pending downgrade) ─────

    public function test_a_scheduled_downgrade_records_the_pending_plan_and_effective_date(): void
    {
        config(['services.stripe.prices.starter.monthly' => 'price_starter_monthly']);
        $subscription = $this->subscriptionFor($this->tenant, [
            'stripe_customer_id' => 'cus_sched',
            'stripe_subscription_id' => 'sub_sched',
            'plan' => 'firm',
            'status' => 'active',
        ]);
        $effectiveAt = now()->addDays(21)->startOfSecond();

        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_sched_created_1',
            type: 'subscription_schedule.created',
            object: [
                'id' => 'sub_sched_1',
                'subscription' => 'sub_sched',
                'customer' => 'cus_sched',
                'status' => 'active',
                'phases' => [
                    ['start_date' => now()->timestamp, 'items' => [['price' => ['id' => 'price_firm_monthly']]]],
                    ['start_date' => $effectiveAt->timestamp, 'items' => [['price' => ['id' => 'price_starter_monthly']]]],
                ],
            ],
        );

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $subscription->refresh();
        $this->assertSame('starter', $subscription->pending_plan);
        $this->assertSame($effectiveAt->timestamp, $subscription->pending_plan_effective_at->timestamp);
        // The live plan is untouched until the schedule executes.
        $this->assertSame('firm', $subscription->plan);
    }

    public function test_editing_the_schedule_to_a_different_target_updates_the_pending_plan(): void
    {
        config([
            'services.stripe.prices.starter.monthly' => 'price_starter_monthly',
            'services.stripe.prices.professional.monthly' => 'price_professional_monthly',
        ]);
        $subscription = $this->subscriptionFor($this->tenant, [
            'stripe_customer_id' => 'cus_sched2',
            'stripe_subscription_id' => 'sub_sched2',
            'plan' => 'firm',
            'status' => 'active',
            'pending_plan' => 'starter',
            'pending_plan_effective_at' => now()->addDays(10),
        ]);

        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_sched_updated_1',
            type: 'subscription_schedule.updated',
            object: [
                'id' => 'sub_sched_2',
                'subscription' => 'sub_sched2',
                'customer' => 'cus_sched2',
                'status' => 'active',
                'phases' => [
                    ['start_date' => now()->timestamp, 'items' => [['price' => ['id' => 'price_firm_monthly']]]],
                    ['start_date' => now()->addDays(15)->timestamp, 'items' => [['price' => ['id' => 'price_professional_monthly']]]],
                ],
            ],
        );

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $this->assertSame('professional', $subscription->refresh()->pending_plan);
    }

    public function test_releasing_the_schedule_clears_the_pending_plan(): void
    {
        $subscription = $this->subscriptionFor($this->tenant, [
            'stripe_customer_id' => 'cus_sched3',
            'stripe_subscription_id' => 'sub_sched3',
            'plan' => 'firm',
            'status' => 'active',
            'pending_plan' => 'starter',
            'pending_plan_effective_at' => now()->addDays(10),
        ]);

        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_sched_released_1',
            type: 'subscription_schedule.released',
            object: [
                'id' => 'sub_sched_3',
                'subscription' => 'sub_sched3',
                'customer' => 'cus_sched3',
                'status' => 'released',
                'phases' => [],
            ],
        );

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $subscription->refresh();
        $this->assertNull($subscription->pending_plan);
        $this->assertNull($subscription->pending_plan_effective_at);
    }

    public function test_a_schedule_whose_target_is_not_a_downgrade_does_not_set_a_pending_plan(): void
    {
        config(['services.stripe.prices.firm.monthly' => 'price_firm_monthly']);
        $subscription = $this->subscriptionFor($this->tenant, [
            'stripe_customer_id' => 'cus_sched4',
            'stripe_subscription_id' => 'sub_sched4',
            'plan' => 'starter',
            'status' => 'active',
        ]);

        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_sched_up_1',
            type: 'subscription_schedule.created',
            object: [
                'id' => 'sub_sched_4',
                'subscription' => 'sub_sched4',
                'customer' => 'cus_sched4',
                'status' => 'active',
                'phases' => [
                    ['start_date' => now()->timestamp, 'items' => [['price' => ['id' => 'price_starter_monthly']]]],
                    ['start_date' => now()->addDays(10)->timestamp, 'items' => [['price' => ['id' => 'price_firm_monthly']]]],
                ],
            ],
        );

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $this->assertNull($subscription->refresh()->pending_plan);
    }

    public function test_the_executing_subscription_update_clears_the_pending_plan(): void
    {
        config(['services.stripe.prices.starter.monthly' => 'price_starter_monthly']);
        $subscription = $this->subscriptionFor($this->tenant, [
            'stripe_customer_id' => 'cus_exec',
            'stripe_subscription_id' => 'sub_exec',
            'plan' => 'firm',
            'status' => 'active',
            'pending_plan' => 'starter',
            'pending_plan_effective_at' => now()->addDays(3),
        ]);
        $this->tenant['provider']->forceFill(['plan' => 'firm'])->save();

        // The schedule fires: the live subscription is now on the Starter price.
        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_exec_1',
            type: 'customer.subscription.updated',
            object: [
                'id' => 'sub_exec',
                'customer' => 'cus_exec',
                'status' => 'active',
                'items' => ['data' => [[
                    'current_period_end' => now()->addDays(30)->timestamp,
                    'price' => ['id' => 'price_starter_monthly'],
                ]]],
            ],
        );

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $subscription->refresh();
        $this->assertSame('starter', $subscription->plan);
        $this->assertNull($subscription->pending_plan);
        $this->assertNull($subscription->pending_plan_effective_at);
        $this->assertSame('starter', $this->tenant['provider']->refresh()->plan);
    }

    public function test_subscription_update_syncs_the_current_period_start(): void
    {
        config(['services.stripe.prices.firm.monthly' => 'price_firm_monthly']);
        $subscription = $this->subscriptionFor($this->tenant, [
            'stripe_customer_id' => 'cus_pstart',
            'stripe_subscription_id' => 'sub_pstart',
        ]);
        $periodStart = now()->subDays(2)->startOfSecond();

        $this->stripe->nextEvent = new StripeWebhookEventData(
            id: 'evt_pstart_1',
            type: 'customer.subscription.updated',
            object: [
                'id' => 'sub_pstart',
                'customer' => 'cus_pstart',
                'status' => 'active',
                'items' => ['data' => [[
                    'current_period_start' => $periodStart->timestamp,
                    'current_period_end' => now()->addDays(28)->timestamp,
                    'price' => ['id' => 'price_firm_monthly'],
                ]]],
            ],
        );

        $this->postJson('/api/v1/stripe/webhook', [])->assertOk();

        $this->assertSame($periodStart->timestamp, $subscription->refresh()->current_period_starts_at->timestamp);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function subscriptionFor(TestScenarioCollection $tenant, array $overrides = []): Subscription
    {
        $subscription = Subscription::query()->where('provider_id', $tenant['provider']->id)->first();

        $subscription->forceFill($overrides)->save();

        return $subscription;
    }
}
