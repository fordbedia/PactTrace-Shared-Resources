<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\DocumentReadyForSignatureEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\GuestSigningInvitationEmail;
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
            // Regression guard: the client's link must carry the envelope's
            // public_id, never the enumerable internal id — see
            // .claude/rules/signature.md, "Envelope public identifier".
            fn ($mail) => $mail->hasTo($this->tenant['client']->email)
                && str_ends_with($mail->portalUrl, "envelope={$envelope->public_id}"),
        );
    }

    public function test_sent_event_notifies_every_co_signer_but_not_the_client_again(): void
    {
        Mail::fake();

        $envelope = $this->envelope(EnvelopeStatus::Draft, DocumentStatus::Draft);
        Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'provider_signer_id' => '1',
            'email' => $this->tenant['client']->email,
        ]);
        $coSigner = Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'provider_signer_id' => '2',
            'name' => 'Co Signer',
            'email' => 'co-signer@example.com',
        ]);

        $this->useCase->handle($this->event('sent', $envelope));

        Mail::assertQueued(
            GuestSigningInvitationEmail::class,
            fn ($mail) => $mail->hasTo('co-signer@example.com')
                && str_contains($mail->signingUrl, "envelope={$envelope->public_id}")
                && str_contains($mail->signingUrl, 'signingLinkToken='),
        );
        Mail::assertNotQueued(
            GuestSigningInvitationEmail::class,
            fn ($mail) => $mail->hasTo($this->tenant['client']->email),
        );
        // A guest signing token is issued at send time — see
        // .claude/rules/signature.md, "Guest signers".
        $this->assertNotNull($coSigner->fresh()->signing_token_hash);
        $this->assertNotNull($coSigner->fresh()->signing_token_expires_at);
    }

    /**
     * Problem 1 confirmation: notification dispatch is per-envelope and
     * independent, never a batched/chained walk of a signer's backlog — see
     * .claude/rules/signature.md, "Notification dispatch is per-envelope".
     * Each envelope here is matched to its own webhook event purely by
     * `provider_envelope_id` (RecordSignatureCompletionUseCase::handle()),
     * so three envelopes "sent" in quick succession for the same client
     * must produce three independent emails, each addressed and linked to
     * its own envelope — not one email unlocking the next.
     */
    public function test_sending_multiple_envelopes_for_the_same_client_in_quick_succession_notifies_independently(): void
    {
        Mail::fake();

        $envelopeOne = $this->distinctEnvelope();
        $envelopeTwo = $this->distinctEnvelope();
        $envelopeThree = $this->distinctEnvelope();

        $this->useCase->handle($this->event('sent', $envelopeOne));
        $this->useCase->handle($this->event('sent', $envelopeTwo));
        $this->useCase->handle($this->event('sent', $envelopeThree));

        Mail::assertQueuedCount(3);
        foreach ([$envelopeOne, $envelopeTwo, $envelopeThree] as $envelope) {
            Mail::assertQueued(
                DocumentReadyForSignatureEmail::class,
                fn ($mail) => $mail->hasTo($this->tenant['client']->email)
                    && str_ends_with($mail->portalUrl, "envelope={$envelope->public_id}"),
            );
        }
    }

    /**
     * Sending envelope B's notification must not require envelope A (for
     * the same client) to have reached `sent`, `completed`, or any other
     * status first — handling B's webhook before A's must still notify B
     * correctly. Regression guard against ever introducing a chained/
     * sequenced dispatch keyed on "the signer's oldest pending envelope".
     */
    public function test_notifying_one_envelope_does_not_depend_on_another_pending_envelopes_status(): void
    {
        Mail::fake();

        $older = $this->distinctEnvelope();
        $newer = $this->distinctEnvelope();

        // Handle the newer envelope's `sent` event first — the older one is
        // still sitting in Draft, untouched.
        $this->useCase->handle($this->event('sent', $newer));

        $this->assertSame(EnvelopeStatus::Sent, $newer->fresh()->status);
        $this->assertSame(EnvelopeStatus::Draft, $older->fresh()->status);
        Mail::assertQueued(
            DocumentReadyForSignatureEmail::class,
            fn ($mail) => str_ends_with($mail->portalUrl, "envelope={$newer->public_id}"),
        );
        Mail::assertNotQueued(
            DocumentReadyForSignatureEmail::class,
            fn ($mail) => str_ends_with($mail->portalUrl, "envelope={$older->public_id}"),
        );
    }

    /**
     * Same independence guarantee for guest co-signer invitations: each
     * envelope's own co-signer is issued their own token and email on that
     * envelope's `sent` transition — not batched or gated behind a sibling
     * envelope for the same client.
     */
    public function test_co_signer_invitations_across_multiple_envelopes_are_independent(): void
    {
        Mail::fake();

        $envelopeOne = $this->distinctEnvelope();
        Signer::factory()->create(['envelope_id' => $envelopeOne->id, 'provider_signer_id' => '2', 'email' => 'co1@example.com']);

        $envelopeTwo = $this->distinctEnvelope();
        Signer::factory()->create(['envelope_id' => $envelopeTwo->id, 'provider_signer_id' => '2', 'email' => 'co2@example.com']);

        $this->useCase->handle($this->event('sent', $envelopeOne));
        $this->useCase->handle($this->event('sent', $envelopeTwo));

        Mail::assertQueued(
            GuestSigningInvitationEmail::class,
            fn ($mail) => $mail->hasTo('co1@example.com') && str_contains($mail->signingUrl, "envelope={$envelopeOne->public_id}"),
        );
        Mail::assertQueued(
            GuestSigningInvitationEmail::class,
            fn ($mail) => $mail->hasTo('co2@example.com') && str_contains($mail->signingUrl, "envelope={$envelopeTwo->public_id}"),
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

    /**
     * Problem 5 fix: a recipient's own Signer row must reflect `signed` as
     * soon as DocuSign's payload reports them completed — even when the
     * envelope overall hasn't reached `completed` yet (a multi-signer
     * envelope where a co-signer hasn't gone yet). Previously
     * recordSignersCompleted() only ran on the `completed` branch, so this
     * signer's own row stayed `pending` for that whole window — the stale
     * read that let /portal/sign's post-signing status check see a false
     * "nothing was signed". See .claude/rules/signature.md, "Never
     * contradict the authoritative signed status".
     */
    public function test_a_signer_is_recorded_as_signed_even_when_the_envelope_itself_is_not_yet_completed(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Sent, DocumentStatus::Sent);
        Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'email' => $this->tenant['client']->email,
            'status' => 'pending',
        ]);
        Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'email' => 'still-pending-co-signer@example.com',
            'status' => 'pending',
        ]);

        // DocuSign's own envelope-level status is still `delivered`
        // (mapped to VIEWED_EVENTS) — this client finished, the co-signer
        // hasn't, so the envelope as a whole is nowhere near `completed`.
        $this->useCase->handle($this->event('delivered', $envelope, $this->tenant['client']->email));

        $this->assertSame(EnvelopeStatus::Viewed, $envelope->fresh()->status);
        $this->assertDatabaseHas('signers', [
            'envelope_id' => $envelope->id,
            'email' => $this->tenant['client']->email,
            'status' => 'signed',
        ]);
        $this->assertDatabaseHas('signers', [
            'envelope_id' => $envelope->id,
            'email' => 'still-pending-co-signer@example.com',
            'status' => 'pending',
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
        Log::spy();

        // Must not throw — this exercises the "not one of ours" branch.
        $this->useCase->handle(new WebhookEvent('completed', 'no-such-envelope', [], []));

        $this->assertDatabaseCount('audit_logs', 0);

        // Silent-and-unmatched is the exact bug class this class's own
        // docblock warns about — see .claude/rules/signature.md.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'no record of'))
            ->once();
    }

    public function test_an_unrecognised_event_type_is_ignored(): void
    {
        Log::spy();
        $envelope = $this->envelope(EnvelopeStatus::Sent, DocumentStatus::Sent);

        $this->useCase->handle($this->event('some-future-status', $envelope));

        $this->assertSame(EnvelopeStatus::Sent, $envelope->fresh()->status);
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message) => str_contains($message, 'not mapped to any Envelope transition'))
            ->once();
    }

    public function test_a_payload_with_no_extractable_envelope_id_is_logged(): void
    {
        Log::spy();

        $this->useCase->handle(new WebhookEvent('unknown', null, [], ['some' => 'shape docusign never documented']));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'no recognizable envelope id'))
            ->once();
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

    /**
     * A second (or third) envelope for the same tenant/client/document as
     * `envelope()`, but with its own unique `provider_envelope_id` — needed
     * whenever a test creates more than one envelope, since `envelope()`'s
     * id is derived solely from the shared document and would otherwise
     * collide, making `RecordSignatureCompletionUseCase::handle()`'s
     * `where('provider_envelope_id', ...)` lookup match the wrong (or same)
     * row.
     */
    private function distinctEnvelope(): Envelope
    {
        $document = $this->tenant['document'];
        $document->forceFill(['status' => DocumentStatus::Draft])->save();

        return Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'status' => EnvelopeStatus::Draft,
            'provider_envelope_id' => 'docusign-env-' . $document->id . '-' . uniqid(),
        ]);
    }

    private function event(string $type, Envelope $envelope, string ...$completedSignerEmails): WebhookEvent
    {
        return new WebhookEvent($type, $envelope->provider_envelope_id, $completedSignerEmails, []);
    }
}
