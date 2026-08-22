<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\WebhookEvent;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use Throwable;

/**
 * Safety net for DocuSign Connect webhook delivery being slow or — observed
 * repeatedly against this sandbox account — never arriving at all, which
 * otherwise leaves an envelope (and the client/co-signer notification
 * emails RecordSignatureCompletionUseCase only fires on the draft->sent
 * transition) stuck indefinitely even though DocuSign's own side already
 * shows it sent. Finds envelopes still `draft` locally well past the time
 * they'd normally have received that webhook, asks DocuSign directly via
 * ESignatureProvider::fetchEnvelopeStatus(), and — only when that live
 * status has actually moved past draft — feeds it through the exact same
 * RecordSignatureCompletionUseCase the webhook itself uses, so the
 * transition, audit log, and notification emails all happen identically
 * either way.
 *
 * Deliberately scoped to `draft` only, not every non-terminal status: this
 * is the specific failure mode observed (see .claude/rules/signature.md) —
 * a webhook missed entirely after `sent`/`completed` still self-heals the
 * next time *any* later webhook lands, since RecordSignatureCompletionUseCase's
 * transitions are idempotent/forward-only regardless of which status they
 * start from.
 *
 * Scheduled (see backend/routes/console.php via
 * Console\Commands\ReconcileStaleDocusignEnvelopes); not on any HTTP path.
 */
class ReconcileStaleDraftEnvelopes
{
    /**
     * How long an envelope is allowed to sit in `draft` with a
     * provider_envelope_id before being treated as "the sent webhook may
     * have been missed" rather than "still being actively prepared". A
     * draft row is created before the tenant even opens the Sender View
     * (see Flow A step 2), so this must stay comfortably above normal
     * prep+send time.
     */
    private const STALE_AFTER_MINUTES = 5;

    /**
     * DocuSign's own term for an envelope that's a genuine, untouched
     * draft — not something to reconcile.
     */
    private const NOT_YET_SENT_STATUSES = ['created', 'draft'];

    public function __construct(
        private readonly ESignatureProvider $provider,
        private readonly RecordSignatureCompletionUseCase $recordCompletion,
    ) {
    }

    /**
     * @return array{checked: int, reconciled: int}
     */
    public function handle(): array
    {
        $stale = Envelope::query()
            // Deliberately cross-workspace: this is a background admin job
            // with no request-bound workspace context to scope to, and a
            // stuck envelope must be found regardless of which workspace it
            // belongs to.
            ->acrossWorkspaces()
            ->where('status', EnvelopeStatus::Draft)
            ->where('provider', 'docusign')
            ->whereNotNull('provider_envelope_id')
            ->where('created_at', '<=', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->get();

        $reconciled = 0;

        foreach ($stale as $envelope) {
            try {
                $liveStatus = $this->provider->fetchEnvelopeStatus($envelope->provider_envelope_id);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if (in_array($liveStatus, self::NOT_YET_SENT_STATUSES, true)) {
                continue;
            }

            // completedSignerEmails is deliberately empty — fetchEnvelopeStatus()
            // returns only the envelope-level status, not per-recipient detail.
            // If the live status is already 'completed', per-signer bookkeeping
            // still catches up normally the next time a real 'completed'
            // webhook lands, since recordSignersCompleted() runs unconditionally.
            $this->recordCompletion->handle(new WebhookEvent(
                eventType: $liveStatus,
                providerEnvelopeId: $envelope->provider_envelope_id,
                completedSignerEmails: [],
                raw: ['reconciled_from_live_status' => true, 'status' => $liveStatus],
            ));

            $reconciled++;
        }

        return ['checked' => $stale->count(), 'reconciled' => $reconciled];
    }
}
