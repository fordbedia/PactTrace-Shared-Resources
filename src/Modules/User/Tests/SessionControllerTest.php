<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\User\Http\Middleware\ResolveProviderFromHost;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;

/**
 * HTTP coverage for `POST /api/v1/auth/login` (SessionController::store()),
 * focused on Part 3 of subdomain-based portal host resolution — the tenancy
 * cross-check against `resolved_provider` (see
 * .claude/rules/client.md, "Subdomain-based portal host resolution").
 *
 * `ResolveProviderFromHost` is mounted explicitly in `defineRoutes()`,
 * mirroring how `backend/bootstrap/app.php` prepends it to the whole `api`
 * group in the real app — Testbench never runs that bootstrap file, so a
 * plain `LoadsModuleApiRoutes` test would never populate `resolved_provider`
 * at all. `StartSession` is mounted for the same reason ProfileControllerTest
 * needs it: session-based login has nothing to write into without it.
 */
class SessionControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class];
    }

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function defineRoutes($router): void
    {
        $router->prefix('api')
            ->middleware([
                \Illuminate\Session\Middleware\StartSession::class,
                ResolveProviderFromHost::class,
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ])
            ->group(__DIR__ . '/../routes/api.php');
    }

    /**
     * A fully-qualified URL, not a relative path — this is deliberate.
     * `MakesHttpRequests::prepareUrlForRequest()` runs every relative URI
     * through the `url()` helper, which resolves against `config('app.url')`
     * and would silently overwrite whatever `Host` header we tried to pass
     * (Symfony's `Request::create()` always derives `HTTP_HOST` from an
     * absolute URI's own host component, once one is present — see
     * ResolveProviderFromHostTest for the lower-level middleware tests,
     * which sidestep this the same way, by building the `Request` with the
     * host baked into the URL). Passing an already-absolute URL here is
     * `isValidUrl()`'s escape hatch: `url()` returns it unchanged, so the
     * host we actually want reaches the request untouched.
     */
    private function urlFor(string $host, string $path): string
    {
        return 'https://' . $host . $path;
    }

    private function loginAs(string $host, string $email): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->urlFor($host, '/api/v1/auth/login'), [
            'email' => $email,
            'password' => 'password',
        ]);
    }

    public function test_login_on_the_platform_host_is_unaffected_by_the_cross_check(): void
    {
        $tenant = ProviderTenantScenario::make('session-platform');

        $response = $this->loginAs('int.pacttrack.com', $tenant['owner']->email);

        $response->assertOk();
        $response->assertJsonPath('user.email', $tenant['owner']->email);
    }

    public function test_login_from_the_own_tenants_subdomain_succeeds(): void
    {
        $tenant = ProviderTenantScenario::make('session-own-subdomain');
        $tenant['provider']->forceFill(['subdomain' => 'session-firm-a'])->save();

        $response = $this->loginAs('session-firm-a.pacttrack.com', $tenant['clientUser']->email);

        $response->assertOk();
        $response->assertJsonPath('user.email', $tenant['clientUser']->email);
    }

    public function test_a_clients_credentials_are_rejected_on_a_different_tenants_subdomain(): void
    {
        $tenantA = ProviderTenantScenario::make('session-tenant-a');
        $tenantB = ProviderTenantScenario::make('session-tenant-b');
        $tenantB['provider']->forceFill(['subdomain' => 'session-firm-b'])->save();

        // Tenant A's client, real correct credentials, submitted on tenant
        // B's own subdomain login page.
        $response = $this->loginAs('session-firm-b.pacttrack.com', $tenantA['clientUser']->email);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    }

    public function test_the_rejection_message_is_identical_to_a_wrong_password_so_it_cannot_be_used_to_probe_tenancy(): void
    {
        $tenantA = ProviderTenantScenario::make('session-probe-a');
        $tenantB = ProviderTenantScenario::make('session-probe-b');
        $tenantB['provider']->forceFill(['subdomain' => 'session-firm-probe'])->save();

        $wrongTenant = $this->loginAs('session-firm-probe.pacttrack.com', $tenantA['clientUser']->email);
        $wrongPassword = $this->postJson($this->urlFor('int.pacttrack.com', '/api/v1/auth/login'), [
            'email' => $tenantA['clientUser']->email,
            'password' => 'not-the-password',
        ]);

        $wrongTenant->assertStatus(422);
        $wrongPassword->assertStatus(422);
        $this->assertSame(
            $wrongPassword->json('errors.email.0'),
            $wrongTenant->json('errors.email.0'),
        );
    }

    public function test_a_rejected_cross_tenant_login_never_leaves_a_session_active(): void
    {
        $tenantA = ProviderTenantScenario::make('session-teardown-a');
        $tenantB = ProviderTenantScenario::make('session-teardown-b');
        $tenantB['provider']->forceFill(['subdomain' => 'session-firm-teardown'])->save();

        $this->loginAs('session-firm-teardown.pacttrack.com', $tenantA['clientUser']->email)
            ->assertStatus(422);

        // No lingering authenticated session for a follow-up request on the
        // platform host — the login was torn back down completely, not left
        // half-established.
        $this->getJson($this->urlFor('int.pacttrack.com', '/api/v1/user'))
            ->assertStatus(401);
    }

    public function test_a_provider_side_users_own_credentials_are_also_rejected_on_a_different_tenants_subdomain(): void
    {
        $tenantA = ProviderTenantScenario::make('session-owner-a');
        $tenantB = ProviderTenantScenario::make('session-owner-b');
        $tenantB['provider']->forceFill(['subdomain' => 'session-firm-owner-b'])->save();

        $response = $this->loginAs('session-firm-owner-b.pacttrack.com', $tenantA['owner']->email);

        $response->assertStatus(422);
    }

    public function test_login_still_fails_normally_for_a_genuinely_wrong_password_on_a_tenant_subdomain(): void
    {
        $tenant = ProviderTenantScenario::make('session-badpass');
        $tenant['provider']->forceFill(['subdomain' => 'session-firm-badpass'])->save();

        $response = $this->postJson($this->urlFor('session-firm-badpass.pacttrack.com', '/api/v1/auth/login'), [
            'email' => $tenant['clientUser']->email,
            'password' => 'nope',
        ]);

        $response->assertStatus(422);
    }
}
