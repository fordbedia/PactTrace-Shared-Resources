<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services;

use Illuminate\Support\Carbon;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;

/**
 * Merges every timestamped fact about a matter into one "Recent Updates"
 * feed for the client portal (see .claude/rules/matter.md and
 * .claude/rules/signature.md, "Guest signers"). This is real product logic
 * — several time-stamped sources collapsed into one ordering — so it lives
 * as a single-responsibility Infrastructure service, same placement as
 * `MatterProgressCalculator`/`MatterCountFormatter`, rather than scattered
 * `->merge()` calls in a controller.
 *
 * Sources merged, each contributing its own real timestamp column (nothing
 * here is inferred or fabricated):
 *
 *   - `Document.created_at` — one "uploaded" entry per document.
 *   - `Envelope.sent_at` / `Envelope.completed_at` — one "sent" and, once it
 *     exists, one "completed" entry per envelope.
 *   - `Signer.signed_at` — one entry per signer who has actually signed,
 *     including guest co-signers (`Signer::isGuest()`), so an additional
 *     signer's own completion is visible and distinguishable from the
 *     primary client's.
 *   - `Milestone.completed_at` — one entry per completed milestone.
 *
 * Deliberately does NOT read `signature_webhook_events`: inspecting
 * `WebhookEvent::fromDocusignPayload()` and
 * `RecordSignatureCompletionUseCase::recordSignersCompleted()` shows every
 * fact a webhook payload carries (which recipients completed, when) is
 * already mirrored onto `Signer.status`/`signed_at` by the time this runs —
 * pulling the raw payload in as well would only duplicate the same signer
 * entries under a second, less structured source.
 *
 * Callers must eager-load `documents.envelopes.signers`,
 * `documents.uploader` and `milestones` on `$matter` — this class never
 * queries on its own, so a matter with 200 documents costs the same one
 * round trip the controller already made to build the rest of the response.
 */
final class MatterActivityFeedBuilder
{
    /**
     * @return list<array{type: string, title: string, actor: string, at: string}>
     */
    public function build(Matter $matter): array
    {
        $providerName = $matter->relationLoaded('provider') && $matter->provider !== null
            ? ($matter->provider->business_name ?? 'Your provider')
            : 'Your provider';

        $entries = [];

        foreach ($matter->documents as $document) {
            $entries[] = $this->documentUploaded($document);

            foreach ($document->envelopes as $envelope) {
                $sent = $this->envelopeSent($document, $envelope, $providerName);
                if ($sent !== null) {
                    $entries[] = $sent;
                }

                $completed = $this->envelopeCompleted($document, $envelope);
                if ($completed !== null) {
                    $entries[] = $completed;
                }

                foreach ($envelope->signers as $signer) {
                    $signed = $this->signerSigned($document, $signer);
                    if ($signed !== null) {
                        $entries[] = $signed;
                    }
                }
            }
        }

        foreach ($matter->milestones as $milestone) {
            if ($milestone->completed_at === null) {
                continue;
            }

            $entries[] = [
                'type' => 'milestone_completed',
                'title' => "{$milestone->name} completed",
                'actor' => $providerName,
                'at' => Carbon::parse($milestone->completed_at)->toIso8601String(),
            ];
        }

        usort($entries, fn (array $a, array $b) => strcmp($b['at'], $a['at']));

        return $entries;
    }

    private function documentUploaded(Document $document): array
    {
        return [
            'type' => 'document_uploaded',
            'title' => "{$document->name} uploaded",
            'actor' => $document->relationLoaded('uploader') && $document->uploader !== null
                ? $document->uploader->name
                : 'Your provider',
            'at' => Carbon::parse($document->created_at)->toIso8601String(),
        ];
    }

    private function envelopeSent(Document $document, Envelope $envelope, string $providerName): ?array
    {
        if ($envelope->sent_at === null) {
            return null;
        }

        return [
            'type' => 'envelope_sent',
            'title' => "{$document->name} sent for signature",
            'actor' => "Shared by {$providerName}",
            'at' => Carbon::parse($envelope->sent_at)->toIso8601String(),
        ];
    }

    private function envelopeCompleted(Document $document, Envelope $envelope): ?array
    {
        if ($envelope->completed_at === null) {
            return null;
        }

        return [
            'type' => 'envelope_completed',
            'title' => "{$document->name} signed & completed",
            'actor' => 'All signers completed',
            'at' => Carbon::parse($envelope->completed_at)->toIso8601String(),
        ];
    }

    private function signerSigned(Document $document, Signer $signer): ?array
    {
        if ($signer->signed_at === null) {
            return null;
        }

        $who = $signer->isGuest() ? "{$signer->name} (guest signer)" : $signer->name;

        return [
            'type' => 'signer_signed',
            'title' => "{$document->name} signed",
            'actor' => "Signed by {$who}",
            'at' => Carbon::parse($signer->signed_at)->toIso8601String(),
        ];
    }
}
