<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Application\Services\GuestSigningTokenService;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Plan-based white-labeling on the unauthenticated guest signer pages
 * (POST guest-brand) — the rule lives in ProviderBrand/ProviderBrandResolver:
 * Starter = name text only; Professional/Firm = logo, else name; an empty
 * name falls back to "Your Provider"; a downgrade hides the logo without
 * deleting the file.
 */
class GuestBrandTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private const LOGO = 'provider-logos/1/logo.png';

    private TestScenarioCollection $tenant;

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public', ['url' => 'https://cdn.example.test/storage']);
        config(['filesystems.provider_logo_disk' => 'public']);
        $this->tenant = ProviderTenantScenario::make('guest-brand');
    }

    public function test_starter_shows_the_name_and_no_logo_even_when_a_logo_file_exists(): void
    {
        $this->configure('starter', 'Doe Law', self::LOGO);

        $this->brand()->assertOk()->assertExactJson(['name' => 'Doe Law', 'logo_url' => null, 'allows_custom_branding' => false]);
    }

    #[DataProvider('paidPlans')]
    public function test_professional_and_firm_show_the_logo(string $plan): void
    {
        $this->configure($plan, 'Doe Law', self::LOGO);

        $this->brand()->assertOk()
            ->assertJsonPath('name', 'Doe Law')
            ->assertJsonPath('logo_url', 'https://cdn.example.test/storage/' . self::LOGO);
    }

    public static function paidPlans(): array
    {
        return [['professional'], ['firm']];
    }

    public function test_a_paid_plan_without_a_logo_falls_back_to_the_name(): void
    {
        $this->configure('professional', 'Doe Law', null);

        $this->brand()->assertOk()->assertExactJson(['name' => 'Doe Law', 'logo_url' => null, 'allows_custom_branding' => true]);
    }

    public function test_an_empty_business_name_never_crashes_and_uses_the_fallback(): void
    {
        $this->configure('starter', '   ', null);

        $this->brand()->assertOk()->assertJsonPath('name', 'Your Provider');
    }

    public function test_a_downgrade_hides_the_logo_without_deleting_it_and_an_upgrade_restores_it(): void
    {
        Storage::disk('public')->put(self::LOGO, 'png');
        $this->configure('professional', 'Doe Law', self::LOGO);
        $this->assertNotNull($this->brand()->json('logo_url'));

        $this->tenant['provider']->update(['plan' => 'starter']);
        $this->assertNull($this->brand()->json('logo_url'));
        Storage::disk('public')->assertExists(self::LOGO);
        $this->assertSame(self::LOGO, $this->tenant['provider']->fresh()->logo_path);

        $this->tenant['provider']->update(['plan' => 'firm']);
        $this->assertNotNull($this->brand()->json('logo_url'));
    }

    public function test_an_invalid_token_gets_no_brand(): void
    {
        $this->configure('professional', 'Doe Law', self::LOGO);
        [$envelope] = $this->guestEnvelope();

        $this->postJson("/api/signature/envelopes/{$envelope->public_id}/guest-brand", ['signingLinkToken' => 'nope'])
            ->assertNotFound();
    }

    private function configure(string $plan, string $name, ?string $logo): void
    {
        $this->tenant['provider']->update(['plan' => $plan, 'business_name' => $name, 'logo_path' => $logo]);
    }

    private function brand(): \Illuminate\Testing\TestResponse
    {
        [$envelope, $signer] = $this->guestEnvelope();
        $token = app(GuestSigningTokenService::class)->issueFor($signer);

        return $this->postJson("/api/signature/envelopes/{$envelope->public_id}/guest-brand", ['signingLinkToken' => $token]);
    }

    private function guestEnvelope(): array
    {
        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->tenant['document']->id,
            'status' => EnvelopeStatus::Sent,
            'provider_envelope_id' => 'docusign-env-brand-' . uniqid(),
        ]);
        $signer = Signer::factory()->create(['envelope_id' => $envelope->id, 'provider_signer_id' => '2', 'status' => 'pending']);

        return [$envelope, $signer];
    }
}
