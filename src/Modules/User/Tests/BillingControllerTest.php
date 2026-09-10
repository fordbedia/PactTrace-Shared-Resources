<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Stripe\FakeBillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * `/dashboard/billing`'s three real endpoints — real `auth:sanctum`, so this
 * registers SanctumServiceProvider and authenticates with Sanctum::actingAs(),
 * same shape as BrandingControllerTest. FakeBillingProvider stands in for
 * the real Stripe SDK throughout.
 */
class BillingControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private FakeBillingProvider $stripe;

    private TestScenarioCollection $tenant;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class];
    }

    protected function moduleApiRoutes(): array
    {
        return [__DIR__.'/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeBillingProvider;
        $this->app->bind(BillingProvider::class, fn () => $this->stripe);

        $this->tenant = ProviderTenantScenario::make('billing-controller');

        // Clean slate — the scenario seeds a document/envelope/client of its
        // own; the change-plan tests below want exact, known counts.
        Document::query()->delete();
        Envelope::query()->delete();
        Client::query()->update(['status' => 'invited']);
    }

    private function owner(): User
    {
        return $this->tenant['owner'];
    }

    public function test_checkout_requires_authentication(): void
    {
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'professional'])->assertUnauthorized();
    }

    public function test_a_staff_user_cannot_manage_billing(): void
    {
        Sanctum::actingAs($this->tenant['staff']);

        $this->postJson('/api/v1/billing/checkout', ['plan' => 'professional'])->assertForbidden();
    }

    public function test_owner_can_start_checkout(): void
    {
        Sanctum::actingAs($this->owner());
        config(['services.stripe.prices.professional.yearly' => 'price_professional_yearly']);

        $response = $this->postJson('/api/v1/billing/checkout', ['plan' => 'professional', 'billing_interval' => 'yearly']);

        $response->assertOk()->assertJsonStructure(['checkout_url']);
        $this->assertCount(1, $this->stripe->checkoutSessions);
        $this->assertSame((string) $this->tenant['provider']->id, $this->stripe->checkoutSessions[0]->providerId);
    }

    public function test_checkout_success_and_cancel_urls_are_distinct_and_success_carries_the_session_placeholder(): void
    {
        Sanctum::actingAs($this->owner());
        config([
            'services.stripe.prices.professional.monthly' => 'price_professional_monthly',
            'services.stripe.return_url' => 'https://app.example.test/checkout/success',
        ]);

        $this->postJson('/api/v1/billing/checkout', ['plan' => 'professional'])->assertOk();

        $request = $this->stripe->checkoutSessions[0];
        $this->assertSame('https://app.example.test/checkout/success?session_id={CHECKOUT_SESSION_ID}', $request->successUrl);
        $this->assertSame('https://app.example.test/checkout/success?canceled=1', $request->cancelUrl);
        $this->assertNotSame($request->successUrl, $request->cancelUrl);
    }

    public function test_checkout_session_status_requires_authentication(): void
    {
        $this->getJson('/api/v1/billing/checkout-session/cs_test_1/status')->assertUnauthorized();
    }

    public function test_a_staff_user_cannot_read_checkout_session_status(): void
    {
        Sanctum::actingAs($this->tenant['staff']);

        $this->getJson('/api/v1/billing/checkout-session/cs_test_1/status')->assertForbidden();
    }

    public function test_checkout_session_status_reports_a_synced_subscription_as_confirmed(): void
    {
        Sanctum::actingAs($this->owner());

        Subscription::query()->where('provider_id', $this->tenant['provider']->id)->update([
            'plan' => 'professional',
            'status' => 'active',
            'stripe_subscription_id' => 'sub_live',
            'current_period_ends_at' => now()->addDays(20),
        ]);

        $response = $this->getJson('/api/v1/billing/checkout-session/cs_test_ok/status');

        $response->assertOk()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('plan', 'Professional')
            ->assertJsonPath('plan_key', 'professional')
            ->assertJsonPath('billing_email', $this->owner()->email);

        // The already-synced fast path must not have touched Stripe.
        $this->assertSame([], $this->stripe->retrievedCheckoutSessions);
    }

    public function test_checkout_session_status_reports_an_unresolved_session_as_failed(): void
    {
        Sanctum::actingAs($this->owner());

        Subscription::query()->where('provider_id', $this->tenant['provider']->id)->update([
            'status' => 'trialing',
            'stripe_subscription_id' => null,
            'current_period_ends_at' => null,
        ]);

        // FakeBillingProvider returns CheckoutSessionStatus::notFound() by default.
        $this->getJson('/api/v1/billing/checkout-session/cs_bogus/status')
            ->assertOk()
            ->assertJsonPath('status', 'failed');
    }

    public function test_checkout_never_carries_a_stripe_side_trial(): void
    {
        Sanctum::actingAs($this->owner());
        config(['services.stripe.prices.professional.monthly' => 'price_professional_monthly']);

        // Even a provider comfortably inside their sign-up trial window (the
        // scenario's default is a pristine trialing subscription, trial_ends_at
        // ~14 days out) gets an immediate-charge session — the sign-up trial is
        // the only trial; Checkout is the conversion to paid.
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'professional'])->assertOk();

        $this->assertCount(1, $this->stripe->checkoutSessions);
        $request = $this->stripe->checkoutSessions[0];
        $this->assertObjectNotHasProperty('trialPeriodDays', $request);
    }

    public function test_portal_session_requires_an_existing_stripe_customer(): void
    {
        Sanctum::actingAs($this->owner());

        Subscription::query()->where('provider_id', $this->tenant['provider']->id)
            ->update(['stripe_customer_id' => null]);

        $this->getJson('/api/v1/billing/portal-session')->assertStatus(422);
    }

    public function test_portal_session_redirects_when_a_stripe_customer_exists(): void
    {
        Sanctum::actingAs($this->owner());

        Subscription::query()->where('provider_id', $this->tenant['provider']->id)
            ->update(['stripe_customer_id' => 'cus_portal']);

        $response = $this->getJson('/api/v1/billing/portal-session');

        $response->assertOk()->assertJsonStructure(['portal_url']);
    }

    public function test_portal_session_uses_the_permissive_configuration_when_a_downgrade_still_fits(): void
    {
        Sanctum::actingAs($this->owner());
        config([
            'services.stripe.billing_portal_configuration_id' => 'bpc_permissive',
            'services.stripe.billing_portal_configuration_id_restricted' => 'bpc_restricted',
        ]);
        $this->tenant['provider']->update(['plan' => 'firm']);
        Subscription::query()->where('provider_id', $this->tenant['provider']->id)
            ->update(['stripe_customer_id' => 'cus_portal']);

        $this->getJson('/api/v1/billing/portal-session')->assertOk();

        $this->assertSame(['bpc_permissive'], $this->stripe->portalSessionConfigurationIds);
    }

    public function test_portal_session_uses_the_restricted_configuration_when_no_lower_tier_fits(): void
    {
        Sanctum::actingAs($this->owner());
        config([
            'services.stripe.billing_portal_configuration_id' => 'bpc_permissive',
            'services.stripe.billing_portal_configuration_id_restricted' => 'bpc_restricted',
        ]);
        $this->tenant['provider']->update(['plan' => 'firm']);
        Subscription::query()->where('provider_id', $this->tenant['provider']->id)
            ->update(['stripe_customer_id' => 'cus_portal']);

        // 4 total seats — fits Firm (5), busts Professional & Starter (1).
        User::factory()->count(3)->create([
            'provider_id' => $this->tenant['provider']->id,
            'status' => 'active',
        ])->each(fn (User $u) => $u->assignRole('staff'));

        $this->getJson('/api/v1/billing/portal-session')->assertOk();

        $this->assertSame(['bpc_restricted'], $this->stripe->portalSessionConfigurationIds);
    }

    public function test_change_plan_is_blocked_when_seats_exceed_the_target_plan(): void
    {
        Sanctum::actingAs($this->owner());
        $this->givenAnActiveStripeSubscription();

        // The scenario seeds an owner (not a seat) + one staff member (1
        // seat); add 5 more staff so this tenant holds 6 seats, comfortably
        // over Professional's cap of 1.
        User::factory()->count(5)->create(['provider_id' => $this->tenant['provider']->id])
            ->each(fn (User $u) => $u->assignRole('staff'));

        $response = $this->postJson('/api/v1/billing/change-plan', ['target_plan' => 'professional']);

        $response->assertStatus(422);
        $response->assertJsonPath('blockers.0.dimension', 'seats');
        $this->assertCount(0, $this->stripe->subscriptionUpdates);
    }

    public function test_change_plan_is_blocked_when_storage_exceeds_the_target_plan(): void
    {
        Sanctum::actingAs($this->owner());
        $this->givenAnActiveStripeSubscription();
        $this->deactivateStaffSoNoSeatsAreHeld();

        // Storage used is the cached provider column now (see
        // ProviderStorageLedger), not a live document sum — set it directly,
        // the way an upload would.
        $this->tenant['provider']->forceFill([
            'storage_used_bytes' => 120 * 1024 * 1024 * 1024,
        ])->save();

        $response = $this->postJson('/api/v1/billing/change-plan', ['target_plan' => 'professional']);

        $response->assertStatus(422);
        $this->assertSame('storage', $response->json('blockers.0.dimension'));
        $this->assertCount(0, $this->stripe->subscriptionUpdates);
    }

    public function test_change_plan_is_blocked_when_active_clients_exceed_the_target_plan(): void
    {
        Sanctum::actingAs($this->owner());
        $this->givenAnActiveStripeSubscription();
        $this->deactivateStaffSoNoSeatsAreHeld();

        Client::factory()->count(20)->create([
            'provider_id' => $this->tenant['provider']->id,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/billing/change-plan', ['target_plan' => 'starter']);

        $response->assertStatus(422);
        $this->assertSame('clients', $response->json('blockers.0.dimension'));
        $this->assertCount(0, $this->stripe->subscriptionUpdates);
    }

    public function test_a_clean_downgrade_calls_stripes_update_api(): void
    {
        Sanctum::actingAs($this->owner());
        $this->givenAnActiveStripeSubscription();
        $this->deactivateStaffSoNoSeatsAreHeld();

        config(['services.stripe.prices.starter.monthly' => 'price_starter_monthly']);

        $response = $this->postJson('/api/v1/billing/change-plan', ['target_plan' => 'starter']);

        $response->assertOk();
        $response->assertJsonPath('status', 'immediate');
        $response->assertJsonPath('target_plan', 'starter');

        $this->assertCount(1, $this->stripe->subscriptionUpdates);
        $this->assertSame('sub_change_plan', $this->stripe->subscriptionUpdates[0]['subscriptionId']);
        $this->assertSame('price_starter_monthly', $this->stripe->subscriptionUpdates[0]['newPriceId']);
        $this->assertSame('create_prorations', $this->stripe->subscriptionUpdates[0]['prorationBehavior']);
    }

    public function test_an_upgrade_is_applied_immediately(): void
    {
        Sanctum::actingAs($this->owner());
        $this->givenAnActiveStripeSubscription('professional', 'price_professional_monthly');

        config([
            'services.stripe.prices.professional.monthly' => 'price_professional_monthly',
            'services.stripe.prices.firm.monthly' => 'price_firm_monthly',
        ]);

        $response = $this->postJson('/api/v1/billing/change-plan', ['target_plan' => 'firm']);

        $response->assertOk();
        $response->assertJsonPath('status', 'immediate');

        $this->assertCount(1, $this->stripe->subscriptionUpdates);
        $this->assertSame('price_firm_monthly', $this->stripe->subscriptionUpdates[0]['newPriceId']);
        $this->assertSame('create_prorations', $this->stripe->subscriptionUpdates[0]['prorationBehavior']);
    }

    public function test_selecting_the_current_plan_is_a_no_op(): void
    {
        Sanctum::actingAs($this->owner());
        $this->givenAnActiveStripeSubscription('firm', 'price_firm_monthly');

        $response = $this->postJson('/api/v1/billing/change-plan', ['target_plan' => 'firm']);

        $response->assertOk();
        $response->assertJsonPath('status', 'noop');
        $this->assertCount(0, $this->stripe->subscriptionUpdates);
    }

    public function test_change_plan_preview_returns_an_estimate(): void
    {
        Sanctum::actingAs($this->owner());
        $this->givenAnActiveStripeSubscription('professional', 'price_professional_monthly');

        config([
            'services.stripe.prices.professional.monthly' => 'price_professional_monthly',
            'services.stripe.prices.firm.monthly' => 'price_firm_monthly',
        ]);

        $response = $this->postJson('/api/v1/billing/change-plan/preview', ['target_plan' => 'firm']);

        $response->assertOk();
        $response->assertJsonStructure([
            'due_today_cents', 'next_invoice_total_cents', 'next_invoice_date',
            'recurring_amount_cents', 'currency', 'line_items',
        ]);

        $this->assertCount(1, $this->stripe->planChangePreviews);
        $this->assertSame('price_firm_monthly', $this->stripe->planChangePreviews[0]['newPriceId']);
        // A preview never touches the subscription.
        $this->assertCount(0, $this->stripe->subscriptionUpdates);
    }

    public function test_change_plan_preview_is_blocked_when_usage_exceeds_the_target_plan(): void
    {
        Sanctum::actingAs($this->owner());
        $this->givenAnActiveStripeSubscription();

        User::factory()->count(5)->create(['provider_id' => $this->tenant['provider']->id])
            ->each(fn (User $u) => $u->assignRole('staff'));

        $response = $this->postJson('/api/v1/billing/change-plan/preview', ['target_plan' => 'professional']);

        $response->assertStatus(422);
        $response->assertJsonPath('blockers.0.dimension', 'seats');
        $this->assertCount(0, $this->stripe->planChangePreviews);
    }

    public function test_change_plan_requires_an_existing_stripe_subscription(): void
    {
        Sanctum::actingAs($this->owner());

        Subscription::query()->where('provider_id', $this->tenant['provider']->id)
            ->update(['stripe_subscription_id' => null]);

        $this->postJson('/api/v1/billing/change-plan', ['target_plan' => 'starter'])->assertStatus(422);
    }

    /**
     * The scenario fixture seeds an owner (not a seat) + one staff member
     * (1 seat) — Professional/Starter's own 1-seat cap would otherwise block
     * every downgrade test below on `seats` before it reaches the dimension
     * actually under test. Deactivating the staffer drops the tenant to 0
     * seats held.
     */
    private function deactivateStaffSoNoSeatsAreHeld(): void
    {
        $this->tenant['staff']->forceFill(['status' => 'deactivated'])->save();
    }

    private function givenAnActiveStripeSubscription(string $plan = 'firm', string $priceId = 'price_firm_monthly'): void
    {
        config(["services.stripe.prices.{$plan}.monthly" => $priceId]);

        Subscription::query()->where('provider_id', $this->tenant['provider']->id)->update([
            'stripe_customer_id' => 'cus_change_plan',
            'stripe_subscription_id' => 'sub_change_plan',
            'stripe_price_id' => $priceId,
            'plan' => $plan,
            'status' => 'active',
        ]);
    }
}
