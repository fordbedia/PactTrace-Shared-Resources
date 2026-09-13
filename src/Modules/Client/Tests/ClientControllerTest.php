<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Tests;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\ClientInvitationEmail;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;
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
     * Resolved bug, recorded so it doesn't regress: `ClientInvitationEmail`
     * used to be built with `providerData->logo_path` — the raw
     * `providers.logo_path` storage key (e.g.
     * `provider-logos/13/uuid-name.png`) — fed directly into the email's
     * `<img src>`, with no scheme or host. A relative `src` like that has no
     * meaningful base in an email client (it resolved against whatever
     * origin was previewing the message, e.g. a local mail catcher's own
     * `localhost` UI), so the logo rendered broken. `ClientController` now
     * resolves it through `ProviderLogoStorage::url()` (the same port
     * `ProviderResource.logo_url` and `/dashboard/branding` use) before
     * building the DTO — see `providerDataArray()`. See
     * .claude/rules/branding.md and .claude/rules/notification.md,
     * "Client-facing vs. internal email branding".
     */
    public function test_the_invitation_email_carries_a_real_absolute_logo_url_not_the_raw_storage_path(): void
    {
        // `Storage::fake('public')` alone drops the disk's real `url` config
        // (Laravel's fake-disk builder doesn't carry it over) — pass it back
        // explicitly so `.url()` here behaves like the real 'public' disk
        // does (an absolute URL under APP_URL), which is exactly the
        // production behaviour this test is guarding.
        Storage::fake('public', ['url' => rtrim((string) config('app.url'), '/') . '/storage']);

        $rawPath = 'provider-logos/' . $this->tenant['provider']->id . '/logo.png';
        Storage::disk('public')->put($rawPath, 'fake-bytes');
        $this->tenant['provider']->update(['logo_path' => $rawPath, 'disk' => 'public']);

        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson('/api/v1/client/invite', [
            'name' => 'Jordan Blake',
            'email' => 'jordan-logo@example.test',
            'company_name' => 'Blake Consulting',
        ])->assertSuccessful();

        Mail::assertSent(ClientInvitationEmail::class, function (ClientInvitationEmail $mail) use ($rawPath) {
            $logoUrl = $mail->providerData->logo_url;

            return $logoUrl !== null
                && $logoUrl !== $rawPath
                && str_starts_with($logoUrl, 'http')
                && str_ends_with($logoUrl, $rawPath);
        });
    }

    public function test_a_provider_with_no_logo_sends_a_null_logo_url(): void
    {
        $this->tenant['provider']->update(['logo_path' => null]);
        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson('/api/v1/client/invite', [
            'name' => 'Jordan Blake',
            'email' => 'jordan-nologo@example.test',
            'company_name' => 'Blake Consulting',
        ])->assertSuccessful();

        Mail::assertSent(
            ClientInvitationEmail::class,
            fn (ClientInvitationEmail $mail) => $mail->providerData->logo_url === null,
        );
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

    /**
     * `GET /clients` — the /dashboard/clients roster. A Client is
     * provider-scoped, never workspace-scoped (see .claude/rules/client.md),
     * so the roster must list every client of the tenant no matter which
     * workspace the acting user has switched into.
     *
     * Regression for the reported bug: `Client` had the `BelongsToWorkspace`
     * global scope + a `clients.workspace_id` column, so an active workspace
     * context appended `AND clients.workspace_id = <active>` and hid every
     * client with a null workspace_id (all of them — the invite flow sets no
     * workspace). The client showed for an admin whose session had no
     * workspace context but not for the owner who had switched workspaces.
     */
    public function test_the_roster_is_not_filtered_by_the_active_workspace(): void
    {
        // The acting user has switched into a workspace.
        app(CurrentWorkspace::class)->setId($this->tenant['workspace']->id);

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson('/api/v1/clients');

        $response->assertOk();

        $emails = collect($response->json('data'))->pluck('email');
        $this->assertContains($this->tenant['client']->email, $emails);
        $this->assertContains($this->tenant['otherClient']->email, $emails);
    }
}
