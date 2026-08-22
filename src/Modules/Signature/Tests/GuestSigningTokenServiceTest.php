<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Signature\Application\Services\GuestSigningTokenService;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\GuestSigningTokenUnavailableException;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Guest (no PactTrack account) signing token issue/resolve — see
 * .claude/rules/signature.md, "Guest signers".
 */
class GuestSigningTokenServiceTest extends BaseTest
{
    private GuestSigningTokenService $service;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GuestSigningTokenService::class);
        $this->tenant = ProviderTenantScenario::make('guest-token');
    }

    public function test_a_freshly_issued_token_resolves_back_to_the_same_signer(): void
    {
        $envelope = $this->coSignerEnvelope();
        $signer = $this->coSigner($envelope);

        $token = $this->service->issueFor($signer);

        $resolved = $this->service->resolve($envelope, $token);

        $this->assertTrue($resolved->is($signer));
        $this->assertNotNull($signer->fresh()->signing_token_hash);
        $this->assertNotSame($token, $signer->fresh()->signing_token_hash);
    }

    /**
     * Raw token shape: `{unix_ms_timestamp}.{uuid4}.{random_40}` — see
     * GuestSigningTokenService::generateRawToken(). Only the composition is
     * asserted here; the round-trip test above already covers hash-only
     * persistence and correct resolution.
     */
    public function test_the_raw_token_composes_a_timestamp_a_uuid_and_random_entropy(): void
    {
        $envelope = $this->coSignerEnvelope();
        $signer = $this->coSigner($envelope);

        $before = (int) round(microtime(true) * 1000);
        $token = $this->service->issueFor($signer);
        $after = (int) round(microtime(true) * 1000);

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        [$timestamp, $uuid, $random] = $parts;

        $this->assertMatchesRegularExpression('/^\d+$/', $timestamp);
        $this->assertGreaterThanOrEqual($before, (int) $timestamp);
        $this->assertLessThanOrEqual($after, (int) $timestamp);

        $this->assertTrue(Str::isUuid($uuid));

        $this->assertSame(40, strlen($random));
    }

    /**
     * Two tokens issued back to back must never collide even if two
     * requests land in the same millisecond — the random component is the
     * actual uniqueness guarantee, the timestamp is only a floor on top.
     */
    public function test_two_tokens_issued_in_quick_succession_never_collide(): void
    {
        $envelope = $this->coSignerEnvelope();
        $signerOne = $this->coSigner($envelope);
        $signerTwo = Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'provider_signer_id' => '3',
            'status' => 'pending',
        ]);

        $tokenOne = $this->service->issueFor($signerOne);
        $tokenTwo = $this->service->issueFor($signerTwo);

        $this->assertNotSame($tokenOne, $tokenTwo);
        $this->assertNotSame($signerOne->fresh()->signing_token_hash, $signerTwo->fresh()->signing_token_hash);
    }

    public function test_a_tampered_token_is_rejected(): void
    {
        $envelope = $this->coSignerEnvelope();
        $signer = $this->coSigner($envelope);
        $token = $this->service->issueFor($signer);

        $this->expectException(GuestSigningTokenUnavailableException::class);

        try {
            $this->service->resolve($envelope, $token . 'x');
        } catch (GuestSigningTokenUnavailableException $e) {
            $this->assertSame('invalid', $e->reason);
            throw $e;
        }
    }

    public function test_an_unknown_token_is_invalid(): void
    {
        $envelope = $this->coSignerEnvelope();
        $this->coSigner($envelope);

        $this->expectException(GuestSigningTokenUnavailableException::class);

        try {
            $this->service->resolve($envelope, 'not-a-real-token');
        } catch (GuestSigningTokenUnavailableException $e) {
            $this->assertSame('invalid', $e->reason);
            throw $e;
        }
    }

    public function test_a_token_scoped_to_a_different_envelope_is_invalid(): void
    {
        $envelope = $this->coSignerEnvelope();
        $signer = $this->coSigner($envelope);
        $token = $this->service->issueFor($signer);

        $otherEnvelope = $this->coSignerEnvelope();

        $this->expectException(GuestSigningTokenUnavailableException::class);

        try {
            $this->service->resolve($otherEnvelope, $token);
        } catch (GuestSigningTokenUnavailableException $e) {
            $this->assertSame('invalid', $e->reason);
            throw $e;
        }
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $envelope = $this->coSignerEnvelope();
        $signer = $this->coSigner($envelope);
        $token = $this->service->issueFor($signer);
        $signer->forceFill(['signing_token_expires_at' => now()->subMinute()])->save();

        $this->expectException(GuestSigningTokenUnavailableException::class);

        try {
            $this->service->resolve($envelope, $token);
        } catch (GuestSigningTokenUnavailableException $e) {
            $this->assertSame('expired', $e->reason);
            throw $e;
        }
    }

    public function test_a_consumed_token_is_rejected(): void
    {
        $envelope = $this->coSignerEnvelope();
        $signer = $this->coSigner($envelope);
        $token = $this->service->issueFor($signer);
        $signer->forceFill(['signing_token_consumed_at' => now()])->save();

        $this->expectException(GuestSigningTokenUnavailableException::class);

        try {
            $this->service->resolve($envelope, $token);
        } catch (GuestSigningTokenUnavailableException $e) {
            $this->assertSame('consumed', $e->reason);
            throw $e;
        }
    }

    public function test_the_primary_client_signer_is_never_a_guest(): void
    {
        $envelope = $this->coSignerEnvelope();
        $primary = Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'provider_signer_id' => '1',
        ]);

        $this->assertFalse($primary->isGuest());
    }

    private function coSignerEnvelope(): Envelope
    {
        return Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->tenant['document']->id,
            'status' => 'sent',
            'provider_envelope_id' => 'docusign-env-guest-token-test-' . uniqid(),
        ]);
    }

    private function coSigner(Envelope $envelope): Signer
    {
        return Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'provider_signer_id' => '2',
            'status' => 'pending',
        ]);
    }
}
