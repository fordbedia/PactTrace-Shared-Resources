<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\DocumentReadyForSignatureEmail;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\ReconcileStaleEnvelopes;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\SigningToken;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\WebhookEvent;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;
use RuntimeException;

/**
 * The scheduled safety net for DocuSign Connect webhook delivery being slow
 * or missing entirely — see .claude/rules/signature.md and
 * ReconcileStaleEnvelopes's own docblock. Covers both the original `draft`
 * branch and the `sent`/`viewed`/`partially_signed` branch added to close
 * the 2026-08-22 "envelope stuck at viewed, completed_at never set" bug —
 * DocuSign Connect never delivered the `completed` webhook even though
 * DocuSign's own side had already completed the envelope.
 */
class ReconcileStaleEnvelopesTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('reconcile-stale');
    }

    /* ── draft branch (pre-existing) ──────────────────────────────────── */

    public function test_a_stale_draft_that_docusign_confirms_sent_is_reconciled_and_notifies_the_client(): void
    {
        Mail::fake();
        $this->bindLiveStatus(['stale-env' => 'sent']);

        $envelope = $this->draftEnvelope('stale-env', minutesOld: 10);

        $result = app(ReconcileStaleEnvelopes::class)->handle();

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

        $result = app(ReconcileStaleEnvelopes::class)->handle();

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

        $result = app(ReconcileStaleEnvelopes::class)->handle();

        $this->assertSame(['checked' => 0, 'reconciled' => 0], $result);
    }

    /* ── in-flight branch (sent/viewed/partially_signed) ─────────────────
     * Added for the 2026-08-22 bug — see class docblock. */

    public function test_an_envelope_stuck_at_viewed_that_docusign_confirms_completed_is_reconciled(): void
    {
        $this->bindLiveStatus(['stuck-viewed' => 'completed']);

        $envelope = $this->inFlightEnvelope('stuck-viewed', EnvelopeStatus::Viewed, minutesOld: 20);

        $result = app(ReconcileStaleEnvelopes::class)->handle();

        $this->assertSame(['checked' => 1, 'reconciled' => 1], $result);
        $fresh = $envelope->fresh();
        $this->assertSame(EnvelopeStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    /**
     * The gap this closes: fetchEnvelopeStatus() only reports the
     * envelope-level status, so without this every Signer row would stay
     * `pending` forever after a reconciliation — exactly reproducing the
     * "false nothing was signed" bug (see .claude/rules/signature.md,
     * "Never contradict the authoritative signed status") through a
     * different path, since /portal/sign's status check reads Signer.status,
     * not Envelope.status.
     */
    public function test_reconciling_a_completed_envelope_also_marks_every_signer_signed(): void
    {
        $this->bindLiveStatus(['stuck-viewed' => 'completed']);

        $envelope = $this->inFlightEnvelope('stuck-viewed', EnvelopeStatus::Viewed, minutesOld: 20);
        $client = Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'provider_signer_id' => '1',
            'email' => $this->tenant['client']->email,
            'status' => 'pending',
        ]);
        $coSigner = Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'provider_signer_id' => '2',
            'email' => 'guest@example.com',
            'status' => 'pending',
        ]);

        app(ReconcileStaleEnvelopes::class)->handle();

        $this->assertSame('signed', $client->fresh()->status);
        $this->assertSame('signed', $coSigner->fresh()->status);
    }

    public function test_reconciling_a_merely_delivered_envelope_does_not_guess_any_signer_as_signed(): void
    {
        $this->bindLiveStatus(['still-delivered' => 'delivered']);

        $envelope = $this->inFlightEnvelope('still-delivered', EnvelopeStatus::Sent, minutesOld: 20);
        $signer = Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'provider_signer_id' => '1',
            'email' => $this->tenant['client']->email,
            'status' => 'pending',
        ]);

        app(ReconcileStaleEnvelopes::class)->handle();

        $this->assertSame('pending', $signer->fresh()->status);
    }

    public function test_a_missed_webhook_reconciliation_is_logged_loudly(): void
    {
        Log::spy();
        $this->bindLiveStatus(['stuck-viewed' => 'completed']);

        $this->inFlightEnvelope('stuck-viewed', EnvelopeStatus::Viewed, minutesOld: 20);

        app(ReconcileStaleEnvelopes::class)->handle();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'DocuSign Connect webhook was missed'))
            ->once();
    }

    public function test_an_envelope_docusign_still_reports_as_sent_is_left_alone_and_not_counted(): void
    {
        $this->bindLiveStatus(['still-pending' => 'sent']);

        $envelope = $this->inFlightEnvelope('still-pending', EnvelopeStatus::Sent, minutesOld: 20);

        $result = app(ReconcileStaleEnvelopes::class)->handle();

        // Still checked (the API was called), but not "reconciled" — DocuSign
        // confirmed the same status PactTrack already had, so this is a
        // human still sitting on the document, not a missed webhook.
        $this->assertSame(['checked' => 1, 'reconciled' => 0], $result);
        $this->assertSame(EnvelopeStatus::Sent, $envelope->fresh()->status);
    }

    public function test_an_in_flight_envelope_younger_than_its_staleness_window_is_never_even_checked(): void
    {
        $this->bindLiveStatus([]);

        $this->inFlightEnvelope('too-fresh', EnvelopeStatus::Viewed, minutesOld: 2);

        $result = app(ReconcileStaleEnvelopes::class)->handle();

        $this->assertSame(['checked' => 0, 'reconciled' => 0], $result);
    }

    public function test_a_terminal_envelope_is_never_reconciled_regardless_of_age(): void
    {
        $this->bindLiveStatus([]);

        Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->tenant['document']->id,
            'status' => EnvelopeStatus::Completed,
            'provider' => 'docusign',
            'provider_envelope_id' => 'already-done',
            'sent_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $result = app(ReconcileStaleEnvelopes::class)->handle();

        $this->assertSame(['checked' => 0, 'reconciled' => 0], $result);
    }

    public function test_a_fetch_failure_is_logged_and_does_not_stop_the_rest_of_the_batch(): void
    {
        Log::spy();
        $this->bindLiveStatus(['stuck-viewed' => 'completed']);

        $failing = $this->inFlightEnvelope('unreachable', EnvelopeStatus::Sent, minutesOld: 20);
        $healthy = $this->inFlightEnvelope('stuck-viewed', EnvelopeStatus::Viewed, minutesOld: 20);

        $result = app(ReconcileStaleEnvelopes::class)->handle();

        $this->assertSame(2, $result['checked']);
        $this->assertSame(1, $result['reconciled']);
        $this->assertSame(EnvelopeStatus::Sent, $failing->fresh()->status);
        $this->assertSame(EnvelopeStatus::Completed, $healthy->fresh()->status);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'fetchEnvelopeStatus failed'))
            ->once();
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

    private function inFlightEnvelope(string $providerEnvelopeId, EnvelopeStatus $status, int $minutesOld): Envelope
    {
        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->tenant['document']->id,
            'status' => $status,
            'provider' => 'docusign',
            'provider_envelope_id' => $providerEnvelopeId,
            'sent_at' => now()->subMinutes($minutesOld),
            'created_at' => now()->subMinutes($minutesOld),
        ]);

        // updated_at is what the staleness query keys off for this branch —
        // factory create() otherwise stamps it "now" regardless of the
        // created_at override above.
        $envelope->forceFill(['updated_at' => now()->subMinutes($minutesOld)])->saveQuietly();

        return $envelope;
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
