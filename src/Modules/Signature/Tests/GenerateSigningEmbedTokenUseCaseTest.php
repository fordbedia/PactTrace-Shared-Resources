<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\GenerateSigningEmbedTokenUseCase;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeNotSignableException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeSigningUnavailableException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;
use RuntimeException;

/**
 * FakeSignatureProvider is already bound app-wide by BaseTest — most tests
 * here rely on that. The provider-failure test rebinds the port to a small
 * throwing double instead, to prove the use case translates a raw provider
 * failure (e.g. DocuSign's consent_required) into
 * EnvelopeSigningUnavailableException rather than letting it leak as a bare
 * RuntimeException — see .claude/rules/signature.md.
 */
class GenerateSigningEmbedTokenUseCaseTest extends BaseTest
{
    private GenerateSigningEmbedTokenUseCase $useCase;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCase = app(GenerateSigningEmbedTokenUseCase::class);
        $this->tenant = ProviderTenantScenario::make('signing-token');
    }

    public function test_it_mints_a_token_and_creates_a_local_signer_row(): void
    {
        $envelope = $this->sentEnvelope();

        $token = $this->useCase->handle($envelope, $this->tenant['client']);

        $this->assertNotEmpty($token->token);
        $this->assertNotEmpty($token->signingUrl);

        $this->assertDatabaseHas('signers', [
            'envelope_id' => $envelope->id,
            'email' => $this->tenant['client']->email,
            'provider_signer_id' => $token->providerSignerId,
        ]);
    }

    public function test_it_updates_an_existing_signer_row_rather_than_duplicating(): void
    {
        $envelope = $this->sentEnvelope();

        $existing = Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'email' => $this->tenant['client']->email,
            'status' => 'sent',
        ]);

        $this->useCase->handle($envelope, $this->tenant['client']);

        $this->assertSame(1, Signer::query()->where('envelope_id', $envelope->id)->count());
        $this->assertSame('sent', $existing->fresh()->status);
    }

    public function test_it_refuses_a_draft_envelope_that_was_never_sent(): void
    {
        $envelope = $this->sentEnvelope(['status' => EnvelopeStatus::Draft, 'provider_envelope_id' => null]);

        $this->expectException(EnvelopeNotSignableException::class);

        $this->useCase->handle($envelope, $this->tenant['client']);
    }

    public function test_it_refuses_a_terminal_envelope(): void
    {
        $envelope = $this->sentEnvelope(['status' => EnvelopeStatus::Completed]);

        $this->expectException(EnvelopeNotSignableException::class);

        $this->useCase->handle($envelope, $this->tenant['client']);
    }

    public function test_a_provider_failure_surfaces_as_signing_unavailable(): void
    {
        $this->app->bind(ESignatureProvider::class, function () {
            return new class implements ESignatureProvider {
                public function createDraftEnvelope(string $title, string $fileName, string $fileContents, array $recipients, ?string $externalId = null): string
                {
                    return 'unused';
                }

                public function senderViewUrl(string $providerEnvelopeId, string $returnUrl): string
                {
                    return 'unused';
                }

                public function recipientViewUrl(string $providerEnvelopeId, EnvelopeRecipient $recipient, string $returnUrl, string $recipientId): \PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\SigningToken
                {
                    throw new RuntimeException('DocuSign recipient view request failed (403): consent_required.');
                }

                public function applyBrand(string $providerEnvelopeId, ?string $brandId): void
                {
                    // unused
                }

                public function fetchEnvelopeStatus(string $providerEnvelopeId): string
                {
                    return 'sent';
                }

                public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader): bool
                {
                    return true;
                }

                public function normalizeWebhookEvent(array $payload): \PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\WebhookEvent
                {
                    return \PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\WebhookEvent::fromDocusignPayload($payload);
                }
            };
        });

        $useCase = app(GenerateSigningEmbedTokenUseCase::class);
        $envelope = $this->sentEnvelope();

        $this->expectException(EnvelopeSigningUnavailableException::class);

        $useCase->handle($envelope, $this->tenant['client']);
    }

    private function sentEnvelope(array $overrides = []): Envelope
    {
        return Envelope::factory()->create(array_merge([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->tenant['document']->id,
            'status' => EnvelopeStatus::Sent,
            'provider_envelope_id' => 'docusign-env-1',
        ], $overrides));
    }
}
