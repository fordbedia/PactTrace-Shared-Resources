<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Tests;

use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\ClientInvitationEmail;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * `POST /api/v1/client/invite` — "Invite Client" (ClientController::store()).
 * Registers SanctumServiceProvider and authenticates with Sanctum::actingAs()
 * — BaseTest's shared harness only configures the `web` guard (see the
 * testing-sanctum-guard memo / AuditLogControllerTest).
 */
class ClientControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private TestScenarioCollection $tenant;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class];
    }

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = ProviderTenantScenario::make('client-invite-http');
    }

    public function test_an_owner_can_invite_a_client(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->postJson('/api/v1/client/invite', [
            'name' => 'Jordan Blake',
            'email' => 'jordan@example.test',
            'company_name' => 'Blake Consulting',
        ]);

        $response->assertSuccessful();
        $this->assertDatabaseHas('clients', [
            'provider_id' => $this->tenant['provider']->id,
            'email' => 'jordan@example.test',
        ]);
        Mail::assertSent(ClientInvitationEmail::class);
    }

    /**
     * PlanPolicy gate — see .claude/rules/plan.md. ProviderTenantScenario's
     * default tenant is deliberately on a healthy plan/subscription so
     * unrelated tests never trip this; these two flip that tenant's own
     * state to exercise the gate directly.
     */
    public function test_inviting_is_denied_when_the_subscription_is_not_active(): void
    {
        Subscription::query()->where('provider_id', $this->tenant['provider']->id)->update(['status' => 'expired']);
        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->postJson('/api/v1/client/invite', [
            'name' => 'Jordan Blake',
            'email' => 'blocked@example.test',
        ]);

        $response->assertStatus(403)->assertJsonPath('reason', 'subscription_inactive');
        $this->assertDatabaseMissing('clients', ['email' => 'blocked@example.test']);
        Mail::assertNothingSent();
    }

    public function test_inviting_is_denied_once_the_starter_plans_active_client_limit_is_reached(): void
    {
        $this->tenant['provider']->update(['plan' => 'starter']);
        $limit = Plan::Starter->info()->maxActiveClients;

        // ProviderTenantScenario already seeds two clients of its own
        // ('client', 'otherClient'), but neither is 'active' by default
        // (the factory default status) — force enough active clients to
        // reach the cap regardless of that starting count.
        Client::factory()
            ->count($limit)
            ->create(['provider_id' => $this->tenant['provider']->id, 'status' => 'active']);

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->postJson('/api/v1/client/invite', [
            'name' => 'One Too Many',
            'email' => 'overflow@example.test',
        ]);

        $response->assertStatus(403)->assertJsonPath('reason', 'plan_limit_exceeded');
        $this->assertDatabaseMissing('clients', ['email' => 'overflow@example.test']);
    }
}
