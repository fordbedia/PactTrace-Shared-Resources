<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\WebhookEvent;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safety net for DocuSign Connect webhook delivery being slow or — observed
 * repeatedly against this sandbox account — never arriving at all, which
 * otherwise leaves an envelope (and its notification emails, and its
 * per-signer bookkeeping) stuck indefinitely even though DocuSign's own side
 * has already moved on. Finds envelopes sitting locally well past the time
 * they'd normally have received their next webhook, asks DocuSign directly
 * via ESignatureProvider::fetchEnvelopeStatus(), and — only when that live
 * status actually implies progress — feeds it through the exact same
 * RecordSignatureCompletionUseCase a real webhook uses, so the transition,
 * audit log, and notification emails all happen identically either way.
 *
 * Covers two independent stuck states, each with its own staleness window
 * (see the two constants below) because they have different normal
 * durations:
 *
 * 1. `draft` — the tenant's own Sender View prep. Expected to last minutes,
 *    not hours, so a short window is safe.
 * 2. `sent` / `viewed` / `partially_signed` — waiting on a client or
 *    co-signer to actually open/sign. This is *normally* open-ended (a
 *    real recipient might take days), so this branch is not "assume
 *    something is wrong" — it is "periodically ask DocuSign whether it
 *    privately knows more than we do." A no-op check (DocuSign confirms
 *    the same status PactTrack already has) is cheap and safe to repeat on
 *    every scheduled run for as long as the envelope stays non-terminal.
 *    This is what closes the exact bug reported 2026-08-22: both
 *    recipients had genuinely finished signing in DocuSign, the envelope
 *    was `completed` on DocuSign's own side, but Connect never delivered
 *    that `completed` webhook — `envelopes.status` stayed `viewed` and
 *    `completed_at` stayed null with nothing to self-heal it, because the
 *    previous version of this safety net only ever looked at `draft`.
 *
 * `RecordSignatureCompletionUseCase`'s transitions are idempotent/
 * forward-only, so calling it here is always safe even when DocuSign's live
 * status turns out to match what PactTrack already has.
 *
 * Scheduled (see backend/routes/console.php via
 * Console\Commands\ReconcileStaleDocusignEnvelopes); not on any HTTP path.
 */
class ReconcileStaleEnvelopes
{
    /**
     * How long an envelope is allowed to sit in `draft` with a
     * provider_envelope_id before being treated as "the sent webhook may
     * have been missed" rather than "still being actively prepared". A
     * draft row is created before the tenant even opens the Sender View
     * (see Flow A step 2), so this must stay comfortably above normal
     * prep+send time.
     */
    private const STALE_DRAFT_AFTER_MINUTES = 5;

    /**
     * How long an in-flight envelope (sent/viewed/partially_signed) is
     * allowed to sit with no *local* status change before re-asking
     * DocuSign what it actually thinks the status is. Deliberately longer
     * than the draft window: unlike drafting, sitting sent/viewed for a
     * while is completely normal (a human hasn't gotten to it yet), so this
     * exists to bound how often DocuSign's API gets polled for envelopes
     * that are just waiting on a person, not to flag them as broken.
     * `updated_at` only moves when a real transition is recorded (a no-op
     * reconciliation never saves), so a genuinely-idle envelope keeps
     * getting re-checked every scheduled run past this window until it
     * either progresses or DocuSign confirms it hasn't — that's intentional.
     */
    private const STALE_IN_FLIGHT_AFTER_MINUTES = 15;

    /**
     * DocuSign's own term for an envelope that's a genuine, untouched
     * draft — not something to reconcile.
     */
    private const NOT_YET_SENT_STATUSES = ['created', 'draft'];

    /**
     * @var EnvelopeStatus[]
     */
    private const IN_FLIGHT_STATUSES = [
        EnvelopeStatus::Sent,
        EnvelopeStatus::Viewed,
        EnvelopeStatus::PartiallySigned,
    ];

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
            ->where('provider', 'docusign')
            ->whereNotNull('provider_envelope_id')
            ->where(function ($query) {
                $query
                    ->where(function ($draft) {
                        $draft->where('status', EnvelopeStatus::Draft)
                            ->where('created_at', '<=', now()->subMinutes(self::STALE_DRAFT_AFTER_MINUTES));
                    })
                    ->orWhere(function ($inFlight) {
                        $inFlight->whereIn('status', self::IN_FLIGHT_STATUSES)
                            ->where('updated_at', '<=', now()->subMinutes(self::STALE_IN_FLIGHT_AFTER_MINUTES));
                    });
            })
            ->get();

        $reconciled = 0;

        foreach ($stale as $envelope) {
            try {
                $liveStatus = $this->provider->fetchEnvelopeStatus($envelope->provider_envelope_id);
            } catch (Throwable $e) {
                Log::warning('ReconcileStaleEnvelopes: fetchEnvelopeStatus failed for a stale envelope.', [
                    'envelope_id' => $envelope->id,
                    'provider_envelope_id' => $envelope->provider_envelope_id,
                    'local_status' => $envelope->status->value,
                    'exception' => $e->getMessage(),
                ]);
                report($e);

                continue;
            }

            if (in_array($liveStatus, self::NOT_YET_SENT_STATUSES, true)) {
                continue;
            }

            $previousStatus = $envelope->status;

            // fetchEnvelopeStatus() returns only the envelope-level status,
            // not per-recipient detail — but when that status is DocuSign's
            // own literal 'completed', every recipient on this envelope is
            // guaranteed to be a blocking `Signer` (never a CarbonCopy — see
            // .claude/rules/signature.md, "Every recipient is a DocuSign
            // Signer"), so DocuSign cannot report 'completed' unless all of
            // them finished. It's therefore safe to infer every Signer row
            // as completed here rather than leaving them at `pending`
            // forever waiting for a per-recipient webhook payload that — per
            // this very bug — may never arrive. Deliberately NOT inferred
            // for any other live status (e.g. 'delivered' does not imply any
            // particular recipient finished).
            $completedSignerEmails = $liveStatus === 'completed'
                ? $envelope->signers()->whereNotNull('email')->pluck('email')->all()
                : [];

            $this->recordCompletion->handle(new WebhookEvent(
                eventType: $liveStatus,
                providerEnvelopeId: $envelope->provider_envelope_id,
                completedSignerEmails: $completedSignerEmails,
                raw: ['reconciled_from_live_status' => true, 'status' => $liveStatus],
            ));

            // A no-op is expected and common for the in-flight branch — most
            // stale-checked envelopes really are just still waiting on a
            // human, and DocuSign confirming the same status back is not a
            // missed webhook. Only count/log it as a reconciliation when the
            // local status actually moved.
            if ($envelope->fresh()?->status !== $previousStatus) {
                Log::warning('ReconcileStaleEnvelopes: DocuSign Connect webhook was missed — reconciled from a live status check instead.', [
                    'envelope_id' => $envelope->id,
                    'provider_envelope_id' => $envelope->provider_envelope_id,
                    'previous_local_status' => $previousStatus->value,
                    'live_docusign_status' => $liveStatus,
                ]);

                $reconciled++;
            }
        }

        return ['checked' => $stale->count(), 'reconciled' => $reconciled];
    }
}
