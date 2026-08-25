<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Tests;

use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * HTTP coverage for MattersController — previously nonexistent (see
 * EnvelopeDetailControllerTest's own docblock, and .claude/rules/matter.md),
 * added alongside `/dashboard/matters/{matterId}` becoming a real Next.js
 * route keyed by `Matter::public_id` rather than client-side view state.
 *
 * `show()` needed no code change to support this — `Matter::getRouteKeyName()`
 * already returns `public_id`, and `Route::apiResource('/matters', ...)`
 * relies on implicit route-model binding, so the endpoint has always resolved
 * by `public_id`. This class exists to actually assert that, and the
 * tenant-ownership check, now that a real page depends on both.
 *
 * Registers Laravel\Sanctum\SanctumServiceProvider itself and authenticates
 * via Sanctum::actingAs() — same reasoning as EnvelopeDetailControllerTest:
 * this route sits behind real `auth:sanctum`, and BaseTest's shared harness
 * only configures the `web` guard.
 */
class MattersControllerTest extends BaseTest
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

        $this->tenant = ProviderTenantScenario::make('matters-controller');
    }

    public function test_show_resolves_by_public_id(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->tenant['matter'];

        $response = $this->getJson("/api/v1/matters/{$matter->public_id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $matter->id)
            ->assertJsonPath('data.public_id', $matter->public_id)
            ->assertJsonPath('data.client.id', $this->tenant['client']->id);
    }

    public function test_show_404s_for_a_matters_internal_id_instead_of_its_public_id(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->tenant['matter'];

        $response = $this->getJson("/api/v1/matters/{$matter->id}");

        $response->assertStatus(404);
    }

    public function test_show_rejects_a_matter_belonging_to_a_different_provider(): void
    {
        $otherTenant = ProviderTenantScenario::make('matters-controller-other');
        Sanctum::actingAs($otherTenant['owner']);

        $response = $this->getJson("/api/v1/matters/{$this->tenant['matter']->public_id}");

        $response->assertStatus(403);
    }

    public function test_show_requires_authentication(): void
    {
        $response = $this->getJson("/api/v1/matters/{$this->tenant['matter']->public_id}");

        $response->assertStatus(401);
    }
}
