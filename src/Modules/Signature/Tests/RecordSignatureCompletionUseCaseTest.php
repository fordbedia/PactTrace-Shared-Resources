<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Domain\ValueObjects\DefaultMilestone;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Milestone;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\DocumentReadyForSignatureEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\GuestSigningInvitationEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\SignatureCompletedEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Support\Notification;
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
            // Regression guard: the client's link points at the document's
            // own matter (public_id, never the enumerable internal id), not
            // a bare /portal or a /portal/sign deep link — see
            // .claude/rules/matter.md, "Fix Portal Links to Use
            // matters.public_id".
            fn ($mail) => $mail->hasTo($this->tenant['client']->email)
                && str_ends_with($mail->portalUrl, "/portal/matter/{$this->tenant['matter']->public_id}")
                && $mail->workspaceName === $this->tenant['workspace']->name
                && str_contains($mail->render(), $this->tenant['workspace']->name),
        );
    }

    /**
     * Dispatch-site gating (see .claude/rules/notification.md): a client who
     * has turned "document ready for signature" off in their notification
     * preferences gets no email on the envelope's `sent` transition — but
     * the status transition, document sync and audit log all still happen.
     */
    public function test_sent_event_does_not_email_a_client_who_disabled_that_notification(): void
    {
        Mail::fake();

        Notification::disable('document_ready_for_signature', $this->tenant['clientUser']);

        $envelope = $this->envelope(EnvelopeStatus::Draft, DocumentStatus::Draft);

        $this->useCase->handle($this->event('sent', $envelope));

        Mail::assertNotQueued(DocumentReadyForSignatureEmail::class);
        // The rest of the webhook's work is unaffected by the preference.
        $this->assertSame(EnvelopeStatus::Sent, $envelope->fresh()->status);
        $this->assertSame(DocumentStatus::Sent, $envelope->document->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'envelope.sent',
            'auditable_id' => $envelope->id,
        ]);
    }

    /**
     * The gate keys off the *recipient's* preference, never the acting/other
     * user's: disabling it for an unrelated user leaves this client's email
     * untouched.
     */
    public function test_sent_event_still_emails_when_only_an_unrelated_user_disabled_the_notification(): void
    {
        Mail::fake();

        Notification::disable('document_ready_for_signature', $this->tenant['staff']);

        $envelope = $this->envelope(EnvelopeStatus::Draft, DocumentStatus::Draft);

        $this->useCase->handle($this->event('sent', $envelope));

        Mail::assertQueued(
            DocumentReadyForSignatureEmail::class,
            fn ($mail) => $mail->hasTo($this->tenant['client']->email),
        );
    }

    /**
     * A Document can exist outside any Matter (see .claude/rules/matter.md)
     * — the notification link must fall back to the bare /portal route
     * rather than crashing on a null matter or producing a broken
     * /portal/matter/ URL with no id.
     */
    public function test_sent_event_falls_back_to_bare_portal_when_the_document_has_no_matter(): void
    {
        Mail::fake();

        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => null,
            'uploaded_by' => $this->tenant['owner']->id,
            'status' => DocumentStatus::Draft,
        ]);

        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'status' => EnvelopeStatus::Draft,
            'provider_envelope_id' => 'docusign-env-no-matter',
        ]);

        $this->useCase->handle($this->event('sent', $envelope));

        Mail::assertQueued(
            DocumentReadyForSignatureEmail::class,
            fn ($mail) => str_ends_with($mail->portalUrl, '/portal')
                && ! str_contains($mail->portalUrl, '/portal/matter/'),
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
                && str_contains($mail->signingUrl, 'signingLinkToken=')
                && $mail->workspaceName === $this->tenant['workspace']->name
                && str_contains($mail->render(), $this->tenant['workspace']->name),
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

        // Three independent sends — never batched/deduped into one email,
        // even though all three envelopes' documents share the same matter
        // and therefore the same portalUrl (see .claude/rules/matter.md).
        Mail::assertQueuedCount(3);
        Mail::assertQueued(
            DocumentReadyForSignatureEmail::class,
            fn ($mail) => $mail->hasTo($this->tenant['client']->email)
                && str_ends_with($mail->portalUrl, "/portal/matter/{$this->tenant['matter']->public_id}"),
        );
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
        // Only the newer envelope's `sent` transition happened — exactly
        // one notification goes out, regardless of the older envelope still
        // sitting pending for the same client.
        Mail::assertQueuedCount(1);
        Mail::assertQueued(
            DocumentReadyForSignatureEmail::class,
            fn ($mail) => str_ends_with($mail->portalUrl, "/portal/matter/{$this->tenant['matter']->public_id}"),
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

    /**
     * Confirms the exact scenario reported against the portal's old
     * single-envelope ACTION REQUIRED card: a Matter reused for two
     * Documents, each with its own Envelope and its own additional
     * (co-)signer, both sent. Each co-signer must get their own
     * GuestSigningInvitationEmail, with a distinct signingLinkToken and its
     * own envelope's public_id — never batched, deduped, or collapsed into
     * one email. See .claude/rules/signature.md, "Guest signers" and
     * "Notification dispatch is per-envelope" — this is that guarantee,
     * exercised with two real Documents on the same Matter rather than two
     * Envelopes sharing one Document.
     */
    public function test_two_envelopes_on_different_documents_of_the_same_matter_email_each_co_signer_independently(): void
    {
        Mail::fake();

        $documentOne = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $this->tenant['matter']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'status' => DocumentStatus::Draft,
        ]);
        $envelopeOne = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $documentOne->id,
            'status' => EnvelopeStatus::Draft,
            'provider_envelope_id' => 'docusign-env-doc-one',
        ]);
        Signer::factory()->create(['envelope_id' => $envelopeOne->id, 'provider_signer_id' => '2', 'email' => 'co1@example.com']);

        $documentTwo = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $this->tenant['matter']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'status' => DocumentStatus::Draft,
        ]);
        $envelopeTwo = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $documentTwo->id,
            'status' => EnvelopeStatus::Draft,
            'provider_envelope_id' => 'docusign-env-doc-two',
        ]);
        Signer::factory()->create(['envelope_id' => $envelopeTwo->id, 'provider_signer_id' => '2', 'email' => 'co2@example.com']);

        $this->useCase->handle($this->event('sent', $envelopeOne));
        $this->useCase->handle($this->event('sent', $envelopeTwo));

        $mails = Mail::queued(GuestSigningInvitationEmail::class);
        $this->assertCount(2, $mails);

        $mailToCo1 = $mails->first(fn ($mail) => $mail->hasTo('co1@example.com'));
        $mailToCo2 = $mails->first(fn ($mail) => $mail->hasTo('co2@example.com'));

        $this->assertNotNull($mailToCo1);
        $this->assertNotNull($mailToCo2);
        $this->assertStringContainsString("envelope={$envelopeOne->public_id}", $mailToCo1->signingUrl);
        $this->assertStringContainsString("envelope={$envelopeTwo->public_id}", $mailToCo2->signingUrl);

        // Each co-signer's token is genuinely their own — never the same
        // bearer credential reused across two different envelopes.
        preg_match('/signingLinkToken=([^&]+)/', $mailToCo1->signingUrl, $tokenOneMatch);
        preg_match('/signingLinkToken=([^&]+)/', $mailToCo2->signingUrl, $tokenTwoMatch);
        $this->assertNotSame($tokenOneMatch[1], $tokenTwoMatch[1]);
    }

    /**
     * The one sanctioned exception to "never batched": envelopes created
     * together by "Prepare All for Signature" (see .claude/rules/matter.md)
     * share a batch_id and must collapse into a single client email even
     * though each is its own independent `draft -> sent` webhook
     * transition — the opposite of
     * test_sending_multiple_envelopes_for_the_same_client_in_quick_succession_notifies_independently
     * above, which covers the *unbatched* (batch_id null) case this must
     * not regress.
     */
    public function test_envelopes_sharing_a_batch_id_notify_the_client_only_once(): void
    {
        Mail::fake();

        $batchId = (string) Str::ulid();
        $envelopeOne = $this->distinctEnvelope($batchId);
        $envelopeTwo = $this->distinctEnvelope($batchId);
        $envelopeThree = $this->distinctEnvelope($batchId);

        $this->useCase->handle($this->event('sent', $envelopeOne));
        $this->useCase->handle($this->event('sent', $envelopeTwo));
        $this->useCase->handle($this->event('sent', $envelopeThree));

        Mail::assertQueuedCount(1);
        Mail::assertQueued(
            DocumentReadyForSignatureEmail::class,
            fn ($mail) => $mail->hasTo($this->tenant['client']->email),
        );
    }

    /**
     * A batch collapsing the *client's* email must not touch guest co-signer
     * notifications at all — each batched envelope's own co-signer still
     * gets their own independent email with their own token, exactly as the
     * unbatched case already guarantees (see
     * test_co_signer_invitations_across_multiple_envelopes_are_independent).
     */
    public function test_batched_envelopes_still_email_each_guest_co_signer_independently(): void
    {
        Mail::fake();

        $batchId = (string) Str::ulid();
        $envelopeOne = $this->distinctEnvelope($batchId);
        Signer::factory()->create(['envelope_id' => $envelopeOne->id, 'provider_signer_id' => '2', 'email' => 'co1@example.com']);
        $envelopeTwo = $this->distinctEnvelope($batchId);
        Signer::factory()->create(['envelope_id' => $envelopeTwo->id, 'provider_signer_id' => '2', 'email' => 'co2@example.com']);

        $this->useCase->handle($this->event('sent', $envelopeOne));
        $this->useCase->handle($this->event('sent', $envelopeTwo));

        $mails = Mail::queued(GuestSigningInvitationEmail::class);
        $this->assertCount(2, $mails);
        $this->assertNotNull($mails->first(fn ($mail) => $mail->hasTo('co1@example.com')));
        $this->assertNotNull($mails->first(fn ($mail) => $mail->hasTo('co2@example.com')));
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

    /**
     * Dispatch-site gating (see .claude/rules/notification.md): when an
     * envelope reaches `completed`, the matter's provider-side contact (the
     * assigned staff member, or the owner as fallback) gets a
     * SignatureCompletedEmail — distinct from the client-facing
     * `document_ready_for_signature` email on `draft -> sent`.
     */
    public function test_completed_event_emails_the_provider_side_contact(): void
    {
        Mail::fake();

        $envelope = $this->envelope(EnvelopeStatus::Viewed, DocumentStatus::Sent);

        $this->useCase->handle($this->event('completed', $envelope));

        Mail::assertQueued(
            SignatureCompletedEmail::class,
            fn (SignatureCompletedEmail $mail): bool =>
                $mail->hasTo($this->tenant['owner']->email)
                && $mail->documentName === $this->tenant['document']->name
                && $mail->workspaceName === $this->tenant['workspace']->name
                && str_contains($mail->render(), $this->tenant['workspace']->name),
        );
    }

    public function test_completed_event_emails_the_assigned_staff_member_when_one_is_set(): void
    {
        Mail::fake();

        $this->tenant['matter']->forceFill(['assigned_staff_id' => $this->tenant['staff']->id])->save();

        $envelope = $this->envelope(EnvelopeStatus::Viewed, DocumentStatus::Sent);

        $this->useCase->handle($this->event('completed', $envelope));

        Mail::assertQueued(
            SignatureCompletedEmail::class,
            fn (SignatureCompletedEmail $mail): bool => $mail->hasTo($this->tenant['staff']->email),
        );
        Mail::assertNotQueued(
            SignatureCompletedEmail::class,
            fn (SignatureCompletedEmail $mail): bool => $mail->hasTo($this->tenant['owner']->email),
        );
    }

    /**
     * The gate keys off the recipient's own preference: a contact who turned
     * "signature completed" off gets no email, but the envelope/document
     * status transition and the audit-log entry all still happen.
     */
    public function test_completed_event_does_not_email_a_contact_who_disabled_signature_completed(): void
    {
        Mail::fake();

        Notification::disable('signature_completed', $this->tenant['owner']);

        $envelope = $this->envelope(EnvelopeStatus::Viewed, DocumentStatus::Sent);

        $this->useCase->handle($this->event('completed', $envelope));

        Mail::assertNotQueued(SignatureCompletedEmail::class);
        $this->assertSame(EnvelopeStatus::Completed, $envelope->fresh()->status);
        $this->assertSame(DocumentStatus::Completed, $envelope->document->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'envelope.completed',
            'auditable_id' => $envelope->id,
        ]);
    }

    public function test_completed_event_still_emails_when_only_an_unrelated_user_disabled_signature_completed(): void
    {
        Mail::fake();

        Notification::disable('signature_completed', $this->tenant['staff']);

        $envelope = $this->envelope(EnvelopeStatus::Viewed, DocumentStatus::Sent);

        $this->useCase->handle($this->event('completed', $envelope));

        Mail::assertQueued(
            SignatureCompletedEmail::class,
            fn (SignatureCompletedEmail $mail): bool => $mail->hasTo($this->tenant['owner']->email),
        );
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

    /**
     * .claude/rules/matter.md, "Matter Progress timeline" — the milestone
     * progression this use case now drives alongside the envelope/document
     * transitions above.
     */
    public function test_sent_event_advances_the_matters_review_milestone(): void
    {
        $review = Milestone::factory()->create([
            'matter_id' => $this->tenant['matter']->id,
            'name' => DefaultMilestone::REVIEW,
            'status' => 'pending',
            'completed_at' => null,
        ]);
        $discovery = Milestone::factory()->create([
            'matter_id' => $this->tenant['matter']->id,
            'name' => DefaultMilestone::DISCOVERY,
            'status' => 'pending',
            'completed_at' => null,
        ]);

        $envelope = $this->envelope(EnvelopeStatus::Draft, DocumentStatus::Draft);
        $this->useCase->handle($this->event('sent', $envelope));

        $this->assertSame('completed', $review->fresh()->status);
        $this->assertNotNull($review->fresh()->completed_at);

        // Discovery has no automatic signal — see
        // MilestoneProgressionService's own docblock — so it must stay
        // untouched by an envelope being sent.
        $this->assertSame('pending', $discovery->fresh()->status);
    }

    /**
     * A single document finishing signing must not mark the whole matter's
     * "Completed" milestone done while a sibling document on the same
     * matter still has an unfinished envelope.
     */
    public function test_completed_event_does_not_advance_the_completed_milestone_while_another_envelope_on_the_matter_is_unfinished(): void
    {
        // ProviderTenantScenario itself creates one baseline Envelope
        // against the tenant's document (never transitioned) — remove it so
        // "every envelope on the matter" reflects only the envelopes this
        // test explicitly sets up, per .claude/rules/signature.md's own
        // note that a Document can accumulate more than one Envelope.
        $this->tenant['envelope']->delete();

        $completedMilestone = Milestone::factory()->create([
            'matter_id' => $this->tenant['matter']->id,
            'name' => DefaultMilestone::COMPLETED,
            'status' => 'pending',
            'completed_at' => null,
        ]);

        $envelope = $this->envelope(EnvelopeStatus::Viewed, DocumentStatus::Sent);

        // A second document/envelope on the same matter, still in flight.
        $secondDocument = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $this->tenant['matter']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'status' => DocumentStatus::Sent,
        ]);
        Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $secondDocument->id,
            'status' => EnvelopeStatus::Sent,
            'provider_envelope_id' => 'docusign-env-still-in-flight',
        ]);

        $this->useCase->handle($this->event('completed', $envelope));

        $this->assertSame(EnvelopeStatus::Completed, $envelope->fresh()->status);
        $this->assertSame('pending', $completedMilestone->fresh()->status);
    }

    /**
     * Once every envelope across every document on the matter has
     * independently reached Completed, the matter's "Completed" milestone
     * advances — this is the fix for the "stuck at 0%"/faded-forever
     * "Completed" step reported against the portal timeline. See
     * .claude/rules/matter.md.
     */
    public function test_completed_event_advances_the_completed_milestone_once_every_envelope_on_the_matter_is_done(): void
    {
        // See the previous test's own comment — remove the scenario's
        // baseline (never-transitioned) Envelope so this matter has exactly
        // one real envelope, the one this test completes.
        $this->tenant['envelope']->delete();

        $completedMilestone = Milestone::factory()->create([
            'matter_id' => $this->tenant['matter']->id,
            'name' => DefaultMilestone::COMPLETED,
            'status' => 'pending',
            'completed_at' => null,
        ]);

        $envelope = $this->envelope(EnvelopeStatus::Viewed, DocumentStatus::Sent);

        $this->useCase->handle($this->event('completed', $envelope));

        $this->assertSame(EnvelopeStatus::Completed, $envelope->fresh()->status);
        $this->assertSame('completed', $completedMilestone->fresh()->status);
        $this->assertNotNull($completedMilestone->fresh()->completed_at);
    }

    /**
     * A redundant/no-op reconciliation (DocuSign confirms a status PactTrack
     * already has — see ReconcileStaleEnvelopes) must not re-run milestone
     * progression in a way that breaks idempotency; calling completeMilestone
     * twice is a documented no-op, not an error.
     */
    public function test_a_redundant_completed_event_does_not_error_after_the_milestone_already_advanced(): void
    {
        Milestone::factory()->create([
            'matter_id' => $this->tenant['matter']->id,
            'name' => DefaultMilestone::COMPLETED,
            'status' => 'pending',
            'completed_at' => null,
        ]);

        $envelope = $this->envelope(EnvelopeStatus::Completed, DocumentStatus::Completed);

        // Must not throw — exercises the already-terminal no-op branch with
        // milestone progression now sitting in the same call path.
        $this->useCase->handle($this->event('completed', $envelope));

        $this->assertDatabaseCount('audit_logs', 0);
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
    private function distinctEnvelope(?string $batchId = null): Envelope
    {
        $document = $this->tenant['document'];
        $document->forceFill(['status' => DocumentStatus::Draft])->save();

        return Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'status' => EnvelopeStatus::Draft,
            'batch_id' => $batchId,
            'provider_envelope_id' => 'docusign-env-' . $document->id . '-' . uniqid(),
        ]);
    }

    private function event(string $type, Envelope $envelope, string ...$completedSignerEmails): WebhookEvent
    {
        return new WebhookEvent($type, $envelope->provider_envelope_id, $completedSignerEmails, []);
    }
}
