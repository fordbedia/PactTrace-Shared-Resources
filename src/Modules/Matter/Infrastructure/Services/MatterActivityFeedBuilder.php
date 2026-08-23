<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services;

use Illuminate\Support\Carbon;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

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
 *     exists, one "completed" entry per envelope. A voided envelope
 *     contributes one more entry keyed off `Envelope.updated_at` instead —
 *     see envelopeVoided()'s own docblock for why that column is safe to
 *     use here despite not being dedicated to this purpose.
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
 *
 * `buildForEnvelope()` below is the same merge, narrowed to one document's
 * one envelope — the audit trail on the envelope detail view
 * (/dashboard/signatures/matter/{matterId}, see .claude/rules/signature.md)
 * needed the identical "document uploaded / envelope sent / envelope
 * completed / signer signed" merge this class already builds for the
 * portal's Recent Updates feed, just scoped to a single envelope instead of
 * every envelope across a matter. Extending this class rather than writing
 * a second, slightly-different merge keeps the two feeds from drifting
 * apart on what counts as an event. Callers of buildForEnvelope() must
 * eager-load `document`, `signers` and `provider` on `$envelope`.
 */
final class MatterActivityFeedBuilder
{
    /**
     * @return list<array{type: string, title: string, actor: string, at: string}>
     */
    public function build(Matter $matter): array
    {
        $providerName = $this->resolveProviderName($matter->relationLoaded('provider') ? $matter->provider : null);

        $entries = [];

        foreach ($matter->documents as $document) {
            $entries[] = $this->documentUploaded($document);

            foreach ($document->envelopes as $envelope) {
                $entries = [...$entries, ...$this->envelopeEntries($document, $envelope, $providerName)];
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

    /**
     * @return list<array{type: string, title: string, actor: string, at: string}>
     */
    public function buildForEnvelope(Envelope $envelope): array
    {
        $document = $envelope->document;
        // Not $envelope->provider: `envelopes.provider` is also a plain
        // string column (the e-signature vendor, e.g. 'docusign' — see
        // .claude/rules/signature.md), and Eloquent's attribute access
        // resolves that column before it ever considers the same-named
        // provider() relation. getRelation() is the only way to reach the
        // eager-loaded Provider model here.
        $providerName = $this->resolveProviderName(
            $envelope->relationLoaded('provider') ? $envelope->getRelation('provider') : null
        );

        $entries = [$this->documentUploaded($document), ...$this->envelopeEntries($document, $envelope, $providerName)];

        usort($entries, fn (array $a, array $b) => strcmp($b['at'], $a['at']));

        return $entries;
    }

    /**
     * Every entry one envelope can contribute — shared by build()'s
     * per-matter loop and buildForEnvelope()'s single-envelope call, so the
     * two feeds can never disagree on what an envelope's own lifecycle
     * looks like.
     *
     * @return list<array{type: string, title: string, actor: string, at: string}>
     */
    private function envelopeEntries(Document $document, Envelope $envelope, string $providerName): array
    {
        $entries = [];

        $sent = $this->envelopeSent($document, $envelope, $providerName);
        if ($sent !== null) {
            $entries[] = $sent;
        }

        $completed = $this->envelopeCompleted($document, $envelope);
        if ($completed !== null) {
            $entries[] = $completed;
        }

        $voided = $this->envelopeVoided($document, $envelope);
        if ($voided !== null) {
            $entries[] = $voided;
        }

        foreach ($envelope->signers as $signer) {
            $signed = $this->signerSigned($document, $signer);
            if ($signed !== null) {
                $entries[] = $signed;
            }
        }

        return $entries;
    }

    private function resolveProviderName(?Provider $provider): string
    {
        return $provider?->business_name ?? 'Your provider';
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

    /**
     * `envelopes` has no dedicated `voided_at` column (unlike `sent_at`/
     * `completed_at`) — VoidEnvelopeHandler only flips `status` (see
     * .claude/rules/signature.md). `updated_at` is a reliable stand-in
     * specifically here: markVoided() moves the envelope to a terminal
     * state, and every mark*() transition on Envelope refuses to run again
     * once terminal (assertNotTerminal()), so nothing else can bump
     * `updated_at` after the void — the timestamp on a currently-voided row
     * is always the void itself.
     */
    private function envelopeVoided(Document $document, Envelope $envelope): ?array
    {
        if ($envelope->status !== EnvelopeStatus::Voided) {
            return null;
        }

        return [
            'type' => 'envelope_voided',
            'title' => "{$document->name} voided",
            'actor' => 'Voided',
            'at' => Carbon::parse($envelope->updated_at)->toIso8601String(),
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
