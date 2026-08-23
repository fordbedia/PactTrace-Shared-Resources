<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\DocumentReadyForSignatureEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\GuestSigningInvitationEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Signature\Application\DTO\ProviderData;
use PactTrackSDK\SharedResources\Modules\Signature\Application\Services\GuestSigningTokenService;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeCannotTransitionException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\WebhookEvent;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use Throwable;

/**
 * The webhook-side half of Flow B: turns a normalized WebhookEvent into an
 * Envelope status transition, keeps the linked Document's status in sync,
 * notifies the client (and any ad-hoc co-signers — see notifyCoSigners())
 * the first time an envelope reaches `sent`, and writes the audit trail
 * entry — see .claude/rules/signature.md and .claude/rules/document.md,
 * "Audit trail".
 *
 * Deliberately does NOT do its own idempotency check — DocusignWebhookController
 * already guarantees this is only called once per distinct payload (unique
 * `payload_hash` on signature_webhook_events). It's still safe to call twice
 * regardless, because every Envelope::mark*() transition is itself
 * idempotent/forward-only.
 *
 * `eventType` values are DocuSign's own envelope status strings
 * (sent/delivered/completed/declined/voided — see WebhookEvent::fromDocusignPayload),
 * not DocuSign's underlying Connect event names. `recordSignersCompleted()`
 * runs on every event, independent of which one — DocuSign includes each
 * recipient's own status on every Connect delivery, so an individual
 * `Signer` row can flip to `signed` well before the envelope itself reaches
 * `completed` in a multi-signer envelope. The Envelope's own status still
 * stays driven entirely by DocuSign's envelope-level status string (no
 * separate "partially signed" webhook signal exists to react to for that),
 * which only reports `completed` once every required signer is done — but
 * per-signer state no longer has to wait for that.
 *
 * Every early return below (`providerEnvelopeId` missing, envelope
 * unmatched, event type not mapped to a transition) is logged — see
 * DocusignWebhookController's own docblock. This is not decorative: a
 * DocuSign Connect webhook that arrives, gets accepted, and produces no
 * database change is otherwise indistinguishable from one that never
 * arrived at all, which is exactly how an envelope sat stuck at `viewed`
 * with `completed_at` null (2026-08-22) before anyone noticed — see
 * ReconcileStaleEnvelopes for the companion scheduled safety net.
 */
class RecordSignatureCompletionUseCase
{
    private const SENT_EVENTS = ['sent'];

    private const VIEWED_EVENTS = ['delivered'];

    private const COMPLETED_EVENTS = ['completed'];

    private const DECLINED_EVENTS = ['declined'];

    private const VOIDED_EVENTS = ['voided'];

    /**
     * @var array<string, DocumentStatus>
     */
    private const DOCUMENT_STATUS_MAP = [
        EnvelopeStatus::Sent->value => DocumentStatus::Sent,
        EnvelopeStatus::PartiallySigned->value => DocumentStatus::PartiallySigned,
        EnvelopeStatus::Completed->value => DocumentStatus::Completed,
        EnvelopeStatus::Voided->value => DocumentStatus::Voided,
    ];

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly GuestSigningTokenService $guestSigningTokenService,
    ) {
    }

    public function handle(WebhookEvent $event): void
    {
        if ($event->providerEnvelopeId === null) {
            Log::warning('DocuSign webhook payload carried no recognizable envelope id — check WebhookEvent::fromDocusignPayload against the actual payload shape DocuSign just sent.', [
                'event_type' => $event->eventType,
                'payload_keys' => array_keys($event->raw),
            ]);

            return;
        }

        $envelope = Envelope::query()
            ->where('provider_envelope_id', $event->providerEnvelopeId)
            ->first();

        if ($envelope === null) {
            Log::warning('DocuSign webhook referenced an envelope PactTrack has no record of.', [
                'provider_envelope_id' => $event->providerEnvelopeId,
                'event_type' => $event->eventType,
            ]);

            return;
        }

        $previousStatus = $envelope->status;

        // Recorded unconditionally, before the envelope-level status branch
        // below, and independent of it: DocuSign includes the full
        // recipient list — each with its own status — on every Connect
        // delivery, not only the one payload that finally reports the
        // envelope itself as `completed` (see WebhookEvent's own docblock).
        // A multi-signer envelope can sit at `sent`/`viewed` for a while
        // after one specific recipient has already finished signing;
        // previously this only ran on COMPLETED_EVENTS, which left that
        // recipient's own Signer row at `pending` for that whole window —
        // exactly the stale read that let /portal/sign's post-signing
        // status check (see .claude/rules/signature.md, "Never contradict
        // the authoritative signed status") see a false negative. Safe to
        // call on every event: recordSignersCompleted() is idempotent and a
        // no-op when completedSignerEmails is empty.
        $this->recordSignersCompleted($envelope, $event);

        try {
            if (in_array($event->eventType, self::SENT_EVENTS, true)) {
                $envelope->markSent();
            } elseif (in_array($event->eventType, self::VIEWED_EVENTS, true)) {
                $envelope->markViewed();
            } elseif (in_array($event->eventType, self::COMPLETED_EVENTS, true)) {
                $envelope->markCompleted();
            } elseif (in_array($event->eventType, self::DECLINED_EVENTS, true)) {
                $envelope->markDeclined();
            } elseif (in_array($event->eventType, self::VOIDED_EVENTS, true)) {
                $envelope->markVoided();
            } else {
                Log::info('DocuSign webhook event type not mapped to any Envelope transition — ignored by design.', [
                    'envelope_id' => $envelope->id,
                    'provider_envelope_id' => $event->providerEnvelopeId,
                    'event_type' => $event->eventType,
                ]);

                return;
            }
        } catch (EnvelopeCannotTransitionException) {
            // A redundant/out-of-order event for an envelope already in a
            // terminal state (e.g. DocuSign redelivers `completed` after a
            // network hiccup). Nothing left to record — and this must stay
            // a no-op rather than bubble up, or a legitimate webhook retry
            // would fail forever against an already-terminal envelope. Still
            // worth a low-severity trace: this is expected/benign, not a
            // failure, so it's logged at debug rather than warning.
            Log::debug('DocuSign webhook event ignored: envelope already in a terminal state.', [
                'envelope_id' => $envelope->id,
                'status' => $envelope->status->value,
                'event_type' => $event->eventType,
            ]);

            return;
        }

        if ($envelope->status === $previousStatus) {
            return;
        }

        $this->syncDocumentStatus($envelope);

        if ($previousStatus === EnvelopeStatus::Draft && $envelope->status === EnvelopeStatus::Sent) {
            $this->notifyClient($envelope);
            $this->notifyCoSigners($envelope);
        }

        AuditLog::create([
            'provider_id' => $envelope->provider_id,
            'user_id' => null,
            'action' => "envelope.{$envelope->status->value}",
            'auditable_type' => Envelope::class,
            'auditable_id' => $envelope->id,
            'metadata' => [
                'previous_status' => $previousStatus->value,
                'event_type' => $event->eventType,
                'provider_envelope_id' => $event->providerEnvelopeId,
            ],
        ]);
    }

    /**
     * A Signer row per recipient normally already exists — created
     * `pending` by PrepareEnvelopeForSignature when the envelope was first
     * sent — so this is usually just flipping status; the firstOrNew()
     * fallback only matters for envelopes that predate that (e.g. a
     * manually reconciled backfill). Called on every webhook event
     * regardless of eventType (see the call site above) — `$event->completedSignerEmails`
     * is simply empty on a payload that reports no newly-completed
     * recipients, making the loop below a no-op, so calling this
     * unconditionally is safe.
     */
    private function recordSignersCompleted(Envelope $envelope, WebhookEvent $event): void
    {
        foreach ($event->completedSignerEmails as $email) {
            $signer = Signer::query()->firstOrNew([
                'envelope_id' => $envelope->id,
                'email' => $email,
            ]);

            if (! $signer->exists) {
                $signer->name = $email;
                $signer->routing_order = 1;
            }

            $signer->status = 'signed';
            $signer->signed_at ??= now();

            // A guest signer's token stays hash-matchable after this (so a
            // reopened link still resolves the right Signer for messaging),
            // but is no longer usable to sign again — see
            // GuestSigningTokenService::resolve() and
            // .claude/rules/signature.md, "Guest signers".
            if ($signer->isGuest()) {
                $signer->signing_token_consumed_at ??= now();
            }

            $signer->save();
        }
    }

    private function syncDocumentStatus(Envelope $envelope): void
    {
        $target = self::DOCUMENT_STATUS_MAP[$envelope->status->value] ?? null;

        if ($target === null) {
            return;
        }

        $document = $envelope->document()->first();

        if ($document !== null && $document->status !== $target) {
            $this->documents->save($document, ['status' => $target]);
        }
    }

    /**
     * Best-effort — a mail failure must never break webhook processing
     * (the status transition and audit log above already succeeded, and
     * DocuSign would otherwise retry a payload that was actually handled
     * fine). Follows the same Mailable + DTO pattern as
     * Notification\Mail\ClientInvitationEmail.
     */
    private function notifyClient(Envelope $envelope): void
    {
        try {
            $client = $envelope->client()->first();
            $provider = $envelope->provider()->first();
            $document = $envelope->document()->first();

            if ($client === null || $provider === null) {
                return;
            }

            Mail::to($client->email)->queue(new DocumentReadyForSignatureEmail(
                providerData: ProviderData::fromArray($provider->toArray()),
                clientName: $client->name,
                documentName: $document?->name ?? 'A document',
                portalUrl: $this->buildPortalUrl($document),
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * `{FRONTEND_URL}/portal/matter/{matter.public_id}` when the document
     * being signed belongs to a Matter — bare `{FRONTEND_URL}/portal`
     * otherwise, since a Document can exist outside any Matter (see
     * .claude/rules/matter.md) and `/portal` already handles that case (its
     * own single-matter redirect / multi-matter picker / empty state — see
     * .claude/rules/matter.md, "Matter Progress timeline"). Deliberately not
     * `/portal/sign?envelope=...` — this email's CTA lands the client on the
     * matter's own portal page, not straight into the DocuSign iframe.
     */
    private function buildPortalUrl(?Document $document): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        $matterPublicId = $document?->matter?->public_id;

        return $matterPublicId !== null
            ? $base . '/portal/matter/' . $matterPublicId
            : $base . '/portal';
    }

    /**
     * PactTrack's own guest signing invitation to every ad-hoc co-signer on
     * the envelope — the client's own Signer row always carries
     * `provider_signer_id === '1'` (see DocusignSignatureProvider::RECIPIENT_ID
     * and PrepareEnvelopeForSignature::createSignerRows, both
     * positional/1-indexed the same way), so everything else here is a
     * co-signer, never the client counted twice. Each co-signer is issued a
     * fresh guest signing token here, at send time — see
     * GuestSigningTokenService::issueFor() — and signs embedded, through
     * PactTrack's own branded iframe, not DocuSign's hosted remote-signer
     * email; see .claude/rules/signature.md, "Guest signers". A co-signer
     * with no email on file is simply skipped (no token to deliver it with)
     * rather than treated as an error. Same best-effort contract as
     * notifyClient(): never let a mail failure break webhook processing.
     */
    private function notifyCoSigners(Envelope $envelope): void
    {
        try {
            $client = $envelope->client()->first();
            $provider = $envelope->provider()->first();
            $document = $envelope->document()->first();

            if ($provider === null) {
                return;
            }

            $coSigners = $envelope->signers()
                ->where('provider_signer_id', '!=', '1')
                ->get();

            foreach ($coSigners as $coSigner) {
                if ($coSigner->email === null || $coSigner->email === '') {
                    continue;
                }

                $rawToken = $this->guestSigningTokenService->issueFor($coSigner);

                $signingUrl = rtrim((string) config('app.frontend_url'), '/')
                    . '/portal/sign?signingLinkToken=' . $rawToken . '&envelope=' . $envelope->public_id;

                Mail::to($coSigner->email)->queue(new GuestSigningInvitationEmail(
                    providerData: ProviderData::fromArray($provider->toArray()),
                    signerName: $coSigner->name,
                    documentName: $document?->name ?? 'A document',
                    clientName: $client?->name ?? 'the client',
                    signingUrl: $signingUrl,
                ));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
