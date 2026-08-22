<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
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
 * not DocuSign's underlying Connect event names. `completed` is where every
 * local Signer row DocuSign reports as done gets marked signed — see
 * recordSignersCompleted(). There's still no separate "partially signed"
 * webhook signal to react to per-signer; the Envelope's own status stays
 * driven entirely by DocuSign's envelope-level status string, which only
 * reports `completed` once every required signer is done.
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
            return;
        }

        $envelope = Envelope::query()
            ->where('provider_envelope_id', $event->providerEnvelopeId)
            ->first();

        if ($envelope === null) {
            return;
        }

        $previousStatus = $envelope->status;

        try {
            if (in_array($event->eventType, self::SENT_EVENTS, true)) {
                $envelope->markSent();
            } elseif (in_array($event->eventType, self::VIEWED_EVENTS, true)) {
                $envelope->markViewed();
            } elseif (in_array($event->eventType, self::COMPLETED_EVENTS, true)) {
                $this->recordSignersCompleted($envelope, $event);
                $envelope->markCompleted();
            } elseif (in_array($event->eventType, self::DECLINED_EVENTS, true)) {
                $envelope->markDeclined();
            } elseif (in_array($event->eventType, self::VOIDED_EVENTS, true)) {
                $envelope->markVoided();
            } else {
                return;
            }
        } catch (EnvelopeCannotTransitionException) {
            // A redundant/out-of-order event for an envelope already in a
            // terminal state (e.g. DocuSign redelivers `completed` after a
            // network hiccup). Nothing left to record — and this must stay
            // a no-op rather than bubble up, or a legitimate webhook retry
            // would fail forever against an already-terminal envelope.
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
     * manually reconciled backfill).
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
                portalUrl: rtrim((string) config('app.frontend_url'), '/') . '/portal/sign?envelope=' . $envelope->public_id,
            ));
        } catch (Throwable $e) {
            report($e);
        }
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
