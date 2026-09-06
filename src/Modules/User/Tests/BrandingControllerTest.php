<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * HTTP coverage for `/dashboard/branding` (BrandingController):
 *
 *   PATCH  /api/v1/branding                       accent colour / portal /
 *                                                 email fields
 *   POST   /api/v1/branding/logo                  Portal Logo upload
 *   DELETE /api/v1/branding/logo                  Portal Logo "Remove"
 *   GET    /api/v1/branding/subdomain-available   the availability pill
 *
 * The interesting logic is the double gate: `provider.manage-branding` (who)
 * AND the plan's `allowsCustomBranding` / `allowsCustomDomain` (what).
 */
class BrandingControllerTest extends BaseTest
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

        $this->tenant = ProviderTenantScenario::make('branding');
        Storage::fake('public');
    }

    private function owner(): User
    {
        return $this->tenant['owner'];
    }

    private function provider(): Provider
    {
        return $this->tenant['provider'];
    }

    private function setPlan(string $plan): void
    {
        $this->provider()->forceFill(['plan' => $plan])->save();
    }

    /**
     * Set the plan and (re-)authenticate as the owner with a FRESH user
     * instance, so the controller's `$request->user()->provider` isn't a
     * stale cached relation carrying a previous plan (a real request always
     * loads it fresh).
     */
    private function actAsOwnerOnPlan(string $plan): void
    {
        $this->setPlan($plan);
        Sanctum::actingAs($this->owner()->fresh());
    }

    // ── auth ────────────────────────────────────────────────────────────

    public function test_every_endpoint_requires_authentication(): void
    {
        $this->patchJson('/api/v1/branding', [])->assertStatus(401);
        $this->postJson('/api/v1/branding/logo', [])->assertStatus(401);
        $this->deleteJson('/api/v1/branding/logo', [])->assertStatus(401);
        $this->getJson('/api/v1/branding/subdomain-available?subdomain=foo')->assertStatus(401);
    }

    public function test_a_staff_user_without_the_permission_is_forbidden(): void
    {
        $this->setPlan('firm');
        Sanctum::actingAs($this->tenant['staff']);

        $this->patchJson('/api/v1/branding', ['business_name' => 'New Name'])->assertStatus(403);
    }

    // ── PATCH /branding ─────────────────────────────────────────────────

    public function test_owner_updates_portal_and_email_fields(): void
    {
        $this->actAsOwnerOnPlan('professional');

        $this->patchJson('/api/v1/branding', [
            'business_name' => 'Mitchell Law',
            'timezone' => 'America/New_York',
            'locale' => 'en-US',
            'email_sender_name' => 'Sarah Mitchell',
            'email_reply_to' => 'sarah@example.com',
            'email_powered_by_footer' => false,
        ])->assertOk()
            ->assertJsonPath('data.provider.business_name', 'Mitchell Law')
            ->assertJsonPath('data.provider.timezone', 'America/New_York')
            ->assertJsonPath('data.provider.email_sender_name', 'Sarah Mitchell')
            ->assertJsonPath('data.provider.email_powered_by_footer', false);

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $this->provider()->id,
            'user_id' => $this->owner()->id,
            'action' => 'branding.updated',
        ]);
    }

    public function test_accent_colour_is_normalised_and_persisted_on_a_branding_plan(): void
    {
        $this->actAsOwnerOnPlan('professional');

        $this->patchJson('/api/v1/branding', ['primary_color' => '3b82f6'])
            ->assertOk()
            ->assertJsonPath('data.provider.primary_color', '#3B82F6');

        $this->assertSame('#3B82F6', $this->provider()->refresh()->primary_color);
    }

    public function test_accent_colour_is_forbidden_on_a_starter_plan(): void
    {
        $this->actAsOwnerOnPlan('starter');

        $this->patchJson('/api/v1/branding', ['primary_color' => '#3B82F6'])->assertStatus(403);
    }

    public function test_a_starter_plan_can_still_set_portal_name(): void
    {
        $this->actAsOwnerOnPlan('starter');

        $this->patchJson('/api/v1/branding', ['business_name' => 'Solo Practice'])
            ->assertOk()
            ->assertJsonPath('data.provider.business_name', 'Solo Practice');
    }

    public function test_custom_domain_needs_a_domain_plan(): void
    {
        $this->actAsOwnerOnPlan('starter');
        $this->patchJson('/api/v1/branding', ['custom_domain' => 'portal.example.com'])->assertStatus(403);

        // Fresh acting user so the controller re-reads the plan (see helper).
        $this->actAsOwnerOnPlan('professional');
        $this->patchJson('/api/v1/branding', ['custom_domain' => 'portal.example.com'])
            ->assertOk()
            ->assertJsonPath('data.provider.custom_domain', 'portal.example.com');
    }

    public function test_a_reserved_subdomain_is_rejected(): void
    {
        $this->actAsOwnerOnPlan('professional');

        $this->patchJson('/api/v1/branding', ['subdomain' => 'api'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subdomain');
    }

    // ── logo ────────────────────────────────────────────────────────────

    public function test_owner_uploads_a_logo(): void
    {
        $this->actAsOwnerOnPlan('professional');

        $this->postJson('/api/v1/branding/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 60),
        ])->assertOk()->assertJsonPath('data.provider.logo_url', fn ($url) => is_string($url) && $url !== '');

        $provider = $this->provider()->refresh();
        $this->assertNotNull($provider->logo_path);
        $this->assertSame('public', $provider->disk);
        Storage::disk('public')->assertExists($provider->logo_path);

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $provider->id,
            'action' => 'branding.logo_updated',
        ]);
    }

    public function test_logo_upload_is_forbidden_on_a_starter_plan(): void
    {
        $this->actAsOwnerOnPlan('starter');

        $this->postJson('/api/v1/branding/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertStatus(403);
    }

    public function test_an_svg_logo_is_rejected(): void
    {
        $this->actAsOwnerOnPlan('professional');

        $this->postJson('/api/v1/branding/logo', [
            'logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
        ])->assertStatus(422)->assertJsonValidationErrors('logo');
    }

    public function test_owner_removes_the_logo(): void
    {
        $this->setPlan('professional');
        Storage::disk('public')->put('provider-logos/x/old.png', 'bytes');
        $this->provider()->forceFill(['logo_path' => 'provider-logos/x/old.png', 'disk' => 'public'])->save();

        Sanctum::actingAs($this->owner());

        $this->deleteJson('/api/v1/branding/logo')
            ->assertOk()
            ->assertJsonPath('data.provider.logo_path', null);

        $this->assertNull($this->provider()->refresh()->logo_path);
        Storage::disk('public')->assertMissing('provider-logos/x/old.png');
        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $this->provider()->id,
            'action' => 'branding.logo_removed',
        ]);
    }

    // ── subdomain availability ──────────────────────────────────────────

    public function test_the_tenants_own_subdomain_reads_as_available(): void
    {
        $this->setPlan('professional');
        $own = $this->provider()->subdomain;
        Sanctum::actingAs($this->owner());

        $this->getJson('/api/v1/branding/subdomain-available?subdomain=' . $own)
            ->assertOk()
            ->assertJsonPath('available', true);
    }

    public function test_another_providers_subdomain_reads_as_taken(): void
    {
        $this->setPlan('professional');
        $other = Provider::factory()->create(['subdomain' => 'takenalready']);
        Sanctum::actingAs($this->owner());

        $this->getJson('/api/v1/branding/subdomain-available?subdomain=takenalready')
            ->assertOk()
            ->assertJsonPath('available', false);

        $this->assertNotNull($other->id);
    }

    public function test_a_reserved_subdomain_reads_as_unavailable(): void
    {
        $this->actAsOwnerOnPlan('professional');

        $this->getJson('/api/v1/branding/subdomain-available?subdomain=admin')
            ->assertOk()
            ->assertJsonPath('available', false);
    }
}
