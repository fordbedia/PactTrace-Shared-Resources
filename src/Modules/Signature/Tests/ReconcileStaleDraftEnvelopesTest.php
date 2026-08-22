<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\DocumentReadyForSignatureEmail;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\ReconcileStaleDraftEnvelopes;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\SigningToken;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\WebhookEvent;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;
use RuntimeException;

/**
 * The scheduled safety net for DocuSign Connect webhook delivery being slow
 * or missing entirely — see .claude/rules/signature.md and
 * ReconcileStaleDraftEnvelopes's own docblock.
 */
class ReconcileStaleDraftEnvelopesTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('reconcile-stale');
    }

    public function test_a_stale_draft_that_docusign_confirms_sent_is_reconciled_and_notifies_the_client(): void
    {
        Mail::fake();
        $this->bindLiveStatus(['stale-env' => 'sent']);

        $envelope = $this->draftEnvelope('stale-env', minutesOld: 10);

        $result = app(ReconcileStaleDraftEnvelopes::class)->handle();

        $this->assertSame(['checked' => 1, 'reconciled' => 1], $result);
        $this->assertSame(EnvelopeStatus::Sent, $envelope->fresh()->status);
        Mail::assertQueued(
            DocumentReadyForSignatureEmail::class,
            fn ($mail) => $mail->hasTo($this->tenant['client']->email),
        );
    }

    public function test_a_draft_still_genuinely_in_progress_on_docusigns_side_is_left_alone(): void
    {
        $this->bindLiveStatus(['still-drafting' => 'created']);

        $envelope = $this->draftEnvelope('still-drafting', minutesOld: 10);

        $result = app(ReconcileStaleDraftEnvelopes::class)->handle();

        $this->assertSame(['checked' => 1, 'reconciled' => 0], $result);
        $this->assertSame(EnvelopeStatus::Draft, $envelope->fresh()->status);
    }

    public function test_a_draft_younger_than_the_staleness_window_is_never_even_checked(): void
    {
        // No envelope id registered — fetchEnvelopeStatus() throws if called,
        // proving the query itself excludes this row rather than the check
        // happening to no-op.
        $this->bindLiveStatus([]);

        $this->draftEnvelope('too-fresh', minutesOld: 1);

        $result = app(ReconcileStaleDraftEnvelopes::class)->handle();

        $this->assertSame(['checked' => 0, 'reconciled' => 0], $result);
    }

    public function test_a_non_docusign_provider_or_sent_envelope_is_ignored(): void
    {
        $this->bindLiveStatus([]);

        Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->tenant['document']->id,
            'status' => EnvelopeStatus::Sent,
            'provider' => 'docusign',
            'provider_envelope_id' => 'already-sent',
            'created_at' => now()->subMinutes(10),
        ]);

        $result = app(ReconcileStaleDraftEnvelopes::class)->handle();

        $this->assertSame(['checked' => 0, 'reconciled' => 0], $result);
    }

    private function draftEnvelope(string $providerEnvelopeId, int $minutesOld): Envelope
    {
        $document = $this->tenant['document'];
        $document->forceFill(['status' => DocumentStatus::Draft])->save();

        return Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'status' => EnvelopeStatus::Draft,
            'provider' => 'docusign',
            'provider_envelope_id' => $providerEnvelopeId,
            'created_at' => now()->subMinutes($minutesOld),
        ]);
    }

    /**
     * @param array<string, string> $statusesByEnvelopeId
     */
    private function bindLiveStatus(array $statusesByEnvelopeId): void
    {
        $this->app->bind(ESignatureProvider::class, function () use ($statusesByEnvelopeId) {
            return new class($statusesByEnvelopeId) implements ESignatureProvider {
                /**
                 * @param array<string, string> $statuses
                 */
                public function __construct(private readonly array $statuses)
                {
                }

                public function createDraftEnvelope(string $title, string $fileName, string $fileContents, array $recipients, ?string $externalId = null): string
                {
                    throw new RuntimeException('unused');
                }

                public function senderViewUrl(string $providerEnvelopeId, string $returnUrl): string
                {
                    throw new RuntimeException('unused');
                }

                public function recipientViewUrl(string $providerEnvelopeId, EnvelopeRecipient $recipient, string $returnUrl, string $recipientId): SigningToken
                {
                    throw new RuntimeException('unused');
                }

                public function applyBrand(string $providerEnvelopeId, ?string $brandId): void
                {
                    // unused
                }

                public function fetchEnvelopeStatus(string $providerEnvelopeId): string
                {
                    if (! array_key_exists($providerEnvelopeId, $this->statuses)) {
                        throw new RuntimeException("Unexpected fetchEnvelopeStatus call for [{$providerEnvelopeId}].");
                    }

                    return $this->statuses[$providerEnvelopeId];
                }

                public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader): bool
                {
                    return true;
                }

                public function normalizeWebhookEvent(array $payload): WebhookEvent
                {
                    return WebhookEvent::fromDocusignPayload($payload);
                }
            };
        });
    }
}
