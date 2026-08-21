<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\DocumentReadyForSignatureEmail;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\RecordSignatureCompletionUseCase;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\WebhookEvent;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The webhook-to-domain-transition half of Flow B — see
 * .claude/rules/signature.md and .claude/rules/document.md, "Audit trail".
 */
class RecordSignatureCompletionUseCaseTest extends BaseTest
{
    private RecordSignatureCompletionUseCase $useCase;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCase = app(RecordSignatureCompletionUseCase::class);
        $this->tenant = ProviderTenantScenario::make('record-completion');
    }

    public function test_sent_event_marks_envelope_sent_and_syncs_document(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Draft, DocumentStatus::Draft);

        $this->useCase->handle($this->event('sent', $envelope));

        $this->assertSame(EnvelopeStatus::Sent, $envelope->fresh()->status);
        $this->assertSame(DocumentStatus::Sent, $envelope->document->fresh()->status);
    }

    public function test_sent_event_notifies_the_client_by_email(): void
    {
        Mail::fake();

        $envelope = $this->envelope(EnvelopeStatus::Draft, DocumentStatus::Draft);

        $this->useCase->handle($this->event('sent', $envelope));

        Mail::assertQueued(
            DocumentReadyForSignatureEmail::class,
            fn ($mail) => $mail->hasTo($this->tenant['client']->email),
        );
    }

    public function test_delivered_event_marks_envelope_viewed_without_touching_document_status(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Sent, DocumentStatus::Sent);

        $this->useCase->handle($this->event('delivered', $envelope));

        $this->assertSame(EnvelopeStatus::Viewed, $envelope->fresh()->status);
        $this->assertSame(DocumentStatus::Sent, $envelope->document->fresh()->status);
    }

    public function test_completed_event_records_the_signer(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Viewed, DocumentStatus::Sent);

        $this->useCase->handle($this->event('completed', $envelope, $this->tenant['client']->email));

        $this->assertSame(EnvelopeStatus::Completed, $envelope->fresh()->status);
        $this->assertSame(DocumentStatus::Completed, $envelope->document->fresh()->status);
        $this->assertDatabaseHas('signers', [
            'envelope_id' => $envelope->id,
            'email' => $this->tenant['client']->email,
            'status' => 'signed',
        ]);
    }

    public function test_completed_event_updates_an_existing_signer_rather_than_duplicating(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Viewed, DocumentStatus::Sent);
        Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'email' => $this->tenant['client']->email,
            'status' => 'sent',
        ]);

        $this->useCase->handle($this->event('completed', $envelope, $this->tenant['client']->email));

        $this->assertSame(1, Signer::query()->where('envelope_id', $envelope->id)->count());
    }

    public function test_completed_event_records_every_completed_signer_independently(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Viewed, DocumentStatus::Sent);
        Signer::factory()->create(['envelope_id' => $envelope->id, 'email' => 'co@example.com', 'status' => 'pending']);

        $this->useCase->handle($this->event(
            'completed',
            $envelope,
            $this->tenant['client']->email,
            'co@example.com',
        ));

        $this->assertDatabaseHas('signers', [
            'envelope_id' => $envelope->id,
            'email' => $this->tenant['client']->email,
            'status' => 'signed',
        ]);
        $this->assertDatabaseHas('signers', [
            'envelope_id' => $envelope->id,
            'email' => 'co@example.com',
            'status' => 'signed',
        ]);
    }

    public function test_completed_event_marks_envelope_and_document_completed(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Viewed, DocumentStatus::Sent);

        $this->useCase->handle($this->event('completed', $envelope));

        $this->assertSame(EnvelopeStatus::Completed, $envelope->fresh()->status);
        $this->assertNotNull($envelope->fresh()->completed_at);
        $this->assertSame(DocumentStatus::Completed, $envelope->document->fresh()->status);
    }

    public function test_voided_event_syncs_document_to_voided(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Sent, DocumentStatus::Sent);

        $this->useCase->handle($this->event('voided', $envelope));

        $this->assertSame(EnvelopeStatus::Voided, $envelope->fresh()->status);
        $this->assertSame(DocumentStatus::Voided, $envelope->document->fresh()->status);
    }

    public function test_it_writes_an_audit_log_entry_on_every_real_transition(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Draft, DocumentStatus::Draft);

        $this->useCase->handle($this->event('sent', $envelope));

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $envelope->provider_id,
            'action' => 'envelope.sent',
            'auditable_type' => Envelope::class,
            'auditable_id' => $envelope->id,
        ]);
    }

    public function test_a_redundant_event_for_an_already_terminal_envelope_is_a_silent_no_op(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Completed, DocumentStatus::Completed);

        // Must not throw — a naive implementation would call
        // Envelope::markCompleted() again, which guards terminal states and
        // throws. A webhook retry hitting an already-terminal envelope has
        // to be a no-op, not a 500 that gets retried forever.
        $this->useCase->handle($this->event('completed', $envelope));

        $this->assertDatabaseMissing('audit_logs', [
            'auditable_type' => Envelope::class,
            'auditable_id' => $envelope->id,
        ]);
    }

    public function test_an_event_for_an_unknown_envelope_id_is_ignored(): void
    {
        // Must not throw — this exercises the "not one of ours" branch.
        $this->useCase->handle(new WebhookEvent('completed', 'no-such-envelope', [], []));

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_an_unrecognised_event_type_is_ignored(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Sent, DocumentStatus::Sent);

        $this->useCase->handle($this->event('some-future-status', $envelope));

        $this->assertSame(EnvelopeStatus::Sent, $envelope->fresh()->status);
    }

    private function envelope(EnvelopeStatus $envelopeStatus, DocumentStatus $documentStatus): Envelope
    {
        $document = $this->tenant['document'];
        $document->forceFill(['status' => $documentStatus])->save();

        return Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'status' => $envelopeStatus,
            'provider_envelope_id' => 'docusign-env-' . $document->id,
        ]);
    }

    private function event(string $type, Envelope $envelope, string ...$completedSignerEmails): WebhookEvent
    {
        return new WebhookEvent($type, $envelope->provider_envelope_id, $completedSignerEmails, []);
    }
}
