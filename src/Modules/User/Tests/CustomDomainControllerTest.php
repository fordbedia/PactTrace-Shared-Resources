<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\CustomDomainVerifier;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\CustomHostnameProvisioner;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CustomDomainVerificationResult;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Provisioning\FakeCustomHostnameProvisioner;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * HTTP coverage for the Custom Domain card's own endpoints:
 *
 *   PUT  /api/v1/branding/custom-domain
 *   POST /api/v1/branding/custom-domain/verify
 *
 * See .claude/rules/branding.md, "Custom Domain". Same fixture/helper shape
 * as BrandingControllerTest (self-contained per module test-class
 * convention — see NotificationPreferenceTest).
 */
class CustomDomainControllerTest extends BaseTest
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

        $this->tenant = ProviderTenantScenario::make('custom-domain');
    }

    private function owner(): User
    {
        return $this->tenant['owner'];
    }

    private function provider(): Provider
    {
        return $this->tenant['provider'];
    }

    private function actAsOwnerOnPlan(string $plan): void
    {
        $this->provider()->forceFill(['plan' => $plan])->save();
        Sanctum::actingAs($this->owner()->fresh());
    }

    private function fakeVerifier(CustomDomainVerificationResult $result): void
    {
        $this->app->bind(CustomDomainVerifier::class, fn () => new class($result) implements CustomDomainVerifier {
            public function __construct(private CustomDomainVerificationResult $result)
            {
            }

            public function verify(string $domain, string $expectedToken): CustomDomainVerificationResult
            {
                return $this->result;
            }
        });
    }

    // ── save ────────────────────────────────────────────────────────────

    public function test_starter_tenant_gets_403_hitting_save_endpoint_directly(): void
    {
        $this->actAsOwnerOnPlan('starter');

        $this->putJson('/api/v1/branding/custom-domain', ['custom_domain' => 'portal.example.com'])
            ->assertStatus(403);
    }

    public function test_professional_tenant_can_save_a_custom_domain_and_it_starts_pending(): void
    {
        $this->actAsOwnerOnPlan('professional');

        $this->putJson('/api/v1/branding/custom-domain', ['custom_domain' => 'portal.example.com'])
            ->assertOk()
            ->assertJsonPath('data.provider.custom_domain', 'portal.example.com')
            ->assertJsonPath('data.provider.custom_domain_status', 'pending')
            ->assertJsonPath('data.provider.custom_domain_verified_at', null);

        $provider = $this->provider()->refresh();
        $this->assertNotNull($provider->custom_domain_verification_token);
    }

    public function test_saving_a_domain_already_claimed_by_another_provider_returns_422(): void
    {
        $other = ProviderTenantScenario::make('other-domain-owner');
        $other['provider']->forceFill(['custom_domain' => 'portal.example.com'])->save();

        $this->actAsOwnerOnPlan('professional');

        $this->putJson('/api/v1/branding/custom-domain', ['custom_domain' => 'portal.example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('custom_domain');
    }

    public function test_changing_domain_after_verified_resets_status_to_pending(): void
    {
        $this->actAsOwnerOnPlan('professional');
        $this->provider()->forceFill([
            'custom_domain' => 'old.example.com',
            'custom_domain_status' => 'verified',
            'custom_domain_verified_at' => now(),
        ])->save();
        Sanctum::actingAs($this->owner()->fresh());

        $this->putJson('/api/v1/branding/custom-domain', ['custom_domain' => 'new.example.com'])
            ->assertOk()
            ->assertJsonPath('data.provider.custom_domain', 'new.example.com')
            ->assertJsonPath('data.provider.custom_domain_status', 'pending')
            ->assertJsonPath('data.provider.custom_domain_verified_at', null);
    }

    public function test_clearing_the_domain_resets_to_unverified(): void
    {
        $this->actAsOwnerOnPlan('professional');
        $this->provider()->forceFill([
            'custom_domain' => 'old.example.com',
            'custom_domain_status' => 'verified',
            'custom_domain_verified_at' => now(),
        ])->save();
        Sanctum::actingAs($this->owner()->fresh());

        $this->putJson('/api/v1/branding/custom-domain', ['custom_domain' => null])
            ->assertOk()
            ->assertJsonPath('data.provider.custom_domain', null)
            ->assertJsonPath('data.provider.custom_domain_status', 'unverified');
    }

    public function test_an_invalid_hostname_is_a_422(): void
    {
        $this->actAsOwnerOnPlan('professional');

        $this->putJson('/api/v1/branding/custom-domain', ['custom_domain' => 'not a domain'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('custom_domain');
    }

    // ── verify ──────────────────────────────────────────────────────────

    public function test_verify_endpoint_marks_domain_verified_when_dns_checks_pass(): void
    {
        $this->actAsOwnerOnPlan('professional');
        $this->provider()->forceFill([
            'custom_domain' => 'portal.example.com',
            'custom_domain_status' => 'pending',
            'custom_domain_verification_token' => 'tok123',
        ])->save();
        Sanctum::actingAs($this->owner()->fresh());

        $this->fakeVerifier(new CustomDomainVerificationResult(true, true, 'custom.pacttrack.com'));

        $this->postJson('/api/v1/branding/custom-domain/verify')
            ->assertOk()
            ->assertJsonPath('data.provider.custom_domain_status', 'verified')
            ->assertJsonPath('verification.txt_matched', true)
            ->assertJsonPath('verification.cname_matched', true);

        $provider = $this->provider()->refresh();
        $this->assertNotNull($provider->custom_domain_verified_at);
    }

    public function test_verify_endpoint_reports_which_specific_record_is_missing(): void
    {
        $this->actAsOwnerOnPlan('professional');
        $this->provider()->forceFill([
            'custom_domain' => 'portal.example.com',
            'custom_domain_status' => 'pending',
            'custom_domain_verification_token' => 'tok123',
        ])->save();
        Sanctum::actingAs($this->owner()->fresh());

        $this->fakeVerifier(new CustomDomainVerificationResult(true, false, null));

        $this->postJson('/api/v1/branding/custom-domain/verify')
            ->assertOk()
            ->assertJsonPath('data.provider.custom_domain_status', 'pending')
            ->assertJsonPath('verification.missing', ['cname']);
    }

    public function test_successful_verification_triggers_provisioning(): void
    {
        $this->actAsOwnerOnPlan('professional');
        $this->provider()->forceFill([
            'custom_domain' => 'portal.example.com',
            'custom_domain_status' => 'pending',
            'custom_domain_verification_token' => 'tok123',
        ])->save();
        Sanctum::actingAs($this->owner()->fresh());

        $this->fakeVerifier(new CustomDomainVerificationResult(true, true, 'custom.pacttrack.com'));

        $this->postJson('/api/v1/branding/custom-domain/verify')->assertOk();

        /** @var FakeCustomHostnameProvisioner $provisioner */
        $provisioner = $this->app->make(CustomHostnameProvisioner::class);
        $this->assertCount(1, $provisioner->calls);
        $this->assertSame('provision', $provisioner->calls[0]['method']);
        $this->assertSame('portal.example.com', $provisioner->calls[0]['domain']);

        $provider = $this->provider()->refresh();
        $this->assertNotNull($provider->cloudflare_custom_hostname_id);
        $this->assertNotNull($provider->custom_domain_ssl_status);
    }

    public function test_verify_with_no_domain_set_is_a_422(): void
    {
        $this->actAsOwnerOnPlan('professional');

        $this->postJson('/api/v1/branding/custom-domain/verify')
            ->assertStatus(422)
            ->assertJsonValidationErrors('custom_domain');
    }

    public function test_starter_tenant_gets_403_hitting_verify_endpoint_directly(): void
    {
        $this->actAsOwnerOnPlan('starter');

        $this->postJson('/api/v1/branding/custom-domain/verify')->assertStatus(403);
    }
}
