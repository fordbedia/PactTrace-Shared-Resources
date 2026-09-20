<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Http\Middleware\ResolveProviderFromHost;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;

/**
 * HTTP coverage for `GET /api/v1/portal/login-brand`
 * (PortalBrandingController::loginBrand()) — the public, pre-auth branding
 * lookup for `/portal/login`. See .claude/rules/client.md, "Subdomain-based
 * portal host resolution".
 *
 * `ResolveProviderFromHost` is mounted explicitly in `defineRoutes()` for
 * the same reason SessionControllerTest needs it — Testbench never runs
 * `backend/bootstrap/app.php`, where this is normally prepended to the whole
 * `api` group.
 */
class PortalBrandingControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function defineRoutes($router): void
    {
        $router->prefix('api')
            ->middleware([
                ResolveProviderFromHost::class,
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ])
            ->group(__DIR__ . '/../routes/api.php');
    }

    /**
     * A fully-qualified URL, not a relative path + `Host` header — see
     * SessionControllerTest::urlFor()'s docblock for why a `Host` header
     * alone is silently overwritten by Laravel's own URL-generation-based
     * `prepareUrlForRequest()`.
     */
    private function brand(string $host): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('https://' . $host . '/api/v1/portal/login-brand');
    }

    public function test_it_requires_no_authentication(): void
    {
        $this->brand('int.pacttrack.com')->assertOk();
    }

    public function test_it_returns_the_all_null_shape_when_nothing_is_host_resolved(): void
    {
        $response = $this->brand('int.pacttrack.com');

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'business_name' => null,
                'logo_url' => null,
                'primary_color' => null,
            ],
        ]);
    }

    public function test_a_professional_tenants_subdomain_surfaces_business_name_and_colour(): void
    {
        $tenant = ProviderTenantScenario::make('brand-professional');
        $tenant['provider']->forceFill([
            'subdomain' => 'brand-firm-pro',
            'plan' => 'professional',
            'business_name' => 'Doe & Associates',
            'primary_color' => '#112233',
        ])->save();

        $response = $this->brand('brand-firm-pro.pacttrack.com');

        $response->assertOk();
        $response->assertJsonPath('data.business_name', 'Doe & Associates');
        $response->assertJsonPath('data.primary_color', '#112233');
    }

    public function test_a_starter_tenants_subdomain_shows_the_business_name_but_never_logo_or_colour(): void
    {
        $tenant = ProviderTenantScenario::make('brand-starter');
        $tenant['provider']->forceFill([
            'subdomain' => 'brand-firm-starter',
            'plan' => 'starter',
            'business_name' => 'Starter Firm',
            'primary_color' => '#445566',
            'logo_path' => 'provider-logos/1/logo.png',
        ])->save();

        $response = $this->brand('brand-firm-starter.pacttrack.com');

        $response->assertOk();
        $response->assertJsonPath('data.business_name', 'Starter Firm');
        $response->assertJsonPath('data.primary_color', null);
        $response->assertJsonPath('data.logo_url', null);
    }

    public function test_the_endpoint_never_exposes_client_or_matter_data(): void
    {
        $tenant = ProviderTenantScenario::make('brand-no-leak');
        $tenant['provider']->forceFill(['subdomain' => 'brand-firm-no-leak', 'plan' => 'firm'])->save();

        $response = $this->brand('brand-firm-no-leak.pacttrack.com');

        $response->assertOk();
        $payload = $response->json('data');

        $this->assertSame(['business_name', 'logo_url', 'primary_color', 'allows_custom_branding'], array_keys($payload));
    }

    public function test_an_unknown_subdomain_still_404s_before_ever_reaching_the_controller(): void
    {
        $this->brand('nobody-registered-this.pacttrack.com')->assertNotFound();
    }
}
