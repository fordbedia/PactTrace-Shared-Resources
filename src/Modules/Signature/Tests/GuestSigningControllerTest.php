<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use PactTrackSDK\SharedResources\Modules\Signature\Application\Services\GuestSigningTokenService;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The unauthenticated guest-signing HTTP surface — see
 * .claude/rules/signature.md, "Guest signers". Uses FakeSignatureProvider
 * (bound app-wide by BaseTest), same as every other HTTP test in this
 * module.
 */
class GuestSigningControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private TestScenarioCollection $tenant;

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('guest-signing-controller');
    }

    public function test_a_valid_token_returns_a_signing_url(): void
    {
        [$envelope, $signer] = $this->sentEnvelopeWithCoSigner();
        $token = app(GuestSigningTokenService::class)->issueFor($signer);

        $this->postJson("/api/signature/envelopes/{$envelope->public_id}/guest-signing-token", [
            'signingLinkToken' => $token,
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'signing_url']);
    }

    public function test_an_invalid_token_is_rejected_with_404(): void
    {
        [$envelope] = $this->sentEnvelopeWithCoSigner();

        $this->postJson("/api/signature/envelopes/{$envelope->public_id}/guest-signing-token", [
            'signingLinkToken' => 'not-a-real-token',
        ])
            ->assertStatus(404)
            ->assertJsonPath('reason', 'invalid');
    }

    public function test_an_expired_token_is_rejected_with_410(): void
    {
        [$envelope, $signer] = $this->sentEnvelopeWithCoSigner();
        $token = app(GuestSigningTokenService::class)->issueFor($signer);
        $signer->forceFill(['signing_token_expires_at' => now()->subDay()])->save();

        $this->postJson("/api/signature/envelopes/{$envelope->public_id}/guest-signing-token", [
            'signingLinkToken' => $token,
        ])
            ->assertStatus(410)
            ->assertJsonPath('reason', 'expired');
    }

    public function test_a_consumed_token_is_rejected_with_410(): void
    {
        [$envelope, $signer] = $this->sentEnvelopeWithCoSigner();
        $token = app(GuestSigningTokenService::class)->issueFor($signer);
        $signer->forceFill(['signing_token_consumed_at' => now()])->save();

        $this->postJson("/api/signature/envelopes/{$envelope->public_id}/guest-signing-token", [
            'signingLinkToken' => $token,
        ])
            ->assertStatus(410)
            ->assertJsonPath('reason', 'consumed');
    }

    public function test_the_envelopes_internal_id_never_resolves_the_route(): void
    {
        [$envelope, $signer] = $this->sentEnvelopeWithCoSigner();
        $token = app(GuestSigningTokenService::class)->issueFor($signer);

        $this->postJson("/api/signature/envelopes/{$envelope->id}/guest-signing-token", [
            'signingLinkToken' => $token,
        ])->assertStatus(404);
    }

    /**
     * @return array{0: Envelope, 1: Signer}
     */
    private function sentEnvelopeWithCoSigner(): array
    {
        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->tenant['document']->id,
            'status' => EnvelopeStatus::Sent,
            'provider_envelope_id' => 'docusign-env-guest-controller-' . uniqid(),
        ]);

        $signer = Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'provider_signer_id' => '2',
            'status' => 'pending',
        ]);

        return [$envelope, $signer];
    }
}
