<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\SigningToken;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\WebhookEvent;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The inbound webhook endpoint — see .claude/rules/signature.md. Uses
 * FakeSignatureProvider's always-true verifyWebhookSignature() for the
 * happy-path/idempotency tests (bound app-wide by BaseTest), and a small
 * always-false double for the rejection test.
 */
class DocusignWebhookControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private TestScenarioCollection $tenant;

    private Envelope $envelope;

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('webhook-http');

        $this->envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->tenant['document']->id,
            'status' => EnvelopeStatus::Viewed,
            'provider_envelope_id' => 'docusign-env-webhook',
        ]);

        $this->tenant['document']->forceFill(['status' => DocumentStatus::Sent])->save();
    }

    public function test_a_valid_payload_transitions_the_envelope(): void
    {
        $this->postJson('/api/signature/webhooks/docusign', $this->payload())->assertOk();

        $this->assertSame(EnvelopeStatus::Completed, $this->envelope->fresh()->status);
        $this->assertDatabaseCount('signature_webhook_events', 1);
    }

    public function test_replaying_the_identical_payload_does_not_double_process(): void
    {
        $payload = $this->payload();

        $this->postJson('/api/signature/webhooks/docusign', $payload)->assertOk();
        $this->postJson('/api/signature/webhooks/docusign', $payload)->assertOk();

        $this->assertDatabaseCount('signature_webhook_events', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_a_rejected_signature_never_touches_the_envelope(): void
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

                public function recipientViewUrl(string $providerEnvelopeId, EnvelopeRecipient $recipient, string $returnUrl): SigningToken
                {
                    throw new \RuntimeException('unused');
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
                    return false;
                }

                public function normalizeWebhookEvent(array $payload): WebhookEvent
                {
                    return WebhookEvent::fromDocusignPayload($payload);
                }
            };
        });

        $this->postJson('/api/signature/webhooks/docusign', $this->payload())->assertStatus(401);

        $this->assertSame(EnvelopeStatus::Viewed, $this->envelope->fresh()->status);
        $this->assertDatabaseCount('signature_webhook_events', 0);
    }

    public function test_a_malformed_body_returns_400(): void
    {
        $this->call(
            'POST',
            '/api/signature/webhooks/docusign',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: 'not-json',
        )->assertStatus(400);
    }

    private function payload(): array
    {
        return [
            'event' => 'envelope-completed',
            'data' => [
                'envelopeId' => $this->envelope->provider_envelope_id,
                'envelopeSummary' => [
                    'status' => 'completed',
                    'recipients' => [
                        'signers' => [[
                            'email' => $this->tenant['client']->email,
                            'status' => 'completed',
                            'signedDateTime' => now()->toIso8601String(),
                        ]],
                    ],
                ],
            ],
        ];
    }
}
