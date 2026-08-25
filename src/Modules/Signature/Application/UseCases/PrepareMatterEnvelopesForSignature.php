<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use Throwable;

/**
 * Backs "Prepare All for Signature" on the Matter Detail page — see
 * .claude/rules/matter.md and .claude/rules/signature.md. Deliberately does
 * NOT duplicate envelope creation: every envelope this produces is created
 * by the same PrepareEnvelopeForSignature::handle() the single-document
 * "Prepare for Signature" action already uses, one call per eligible
 * document, tagged with one shared batch id so
 * RecordSignatureCompletionUseCase can later collapse the client
 * notification for the whole batch into a single email (see
 * Envelope::scopeInBatch()).
 *
 * Each document still gets its own separate Envelope — this is explicitly
 * NOT a "combine documents into one envelope" feature. No co-signers are
 * collected in bulk mode; a document that needs an ad-hoc co-signer still
 * goes through the per-document "Prepare for Signature" action, which keeps
 * its own signer-collection step untouched.
 */
class PrepareMatterEnvelopesForSignature
{
    public function __construct(
        private readonly PrepareEnvelopeForSignature $prepareEnvelopeForSignature,
    ) {
    }

    /**
     * @param array<int, array<array{name: string, email: string}>> $coSignersByDocumentId
     *     Ad-hoc co-signers per document, collected up front by
     *     PrepareAllSignatureModal's signer-collection step — see
     *     .claude/rules/matter.md. Keyed by the document's internal id;
     *     a document absent from this map (or the whole map left empty, the
     *     default) gets no co-signers, exactly the prior behavior. Each
     *     entry is passed straight through to
     *     PrepareEnvelopeForSignature::handle() unchanged — this method adds
     *     no validation of its own beyond what that already does.
     * @return array{
     *     prepared: list<array{document_id: int, document_name: string, envelope_id: string, sender_view_url: string}>,
     *     skipped: list<array{document_id: int, document_name: string, reason: string}>,
     * }
     */
    public function handle(Matter $matter, array $coSignersByDocumentId = []): array
    {
        $batchId = (string) Str::ulid();
        $prepared = [];
        $skipped = [];

        $documents = $matter->documents()->with('envelopes')->get();

        foreach ($documents as $document) {
            if ($document->mime_type !== 'application/pdf') {
                $skipped[] = $this->skip($document, 'not_pdf');

                continue;
            }

            if ($this->hasActiveEnvelope($document)) {
                $skipped[] = $this->skip($document, 'already_active');

                continue;
            }

            try {
                $coSigners = $coSignersByDocumentId[$document->id] ?? [];
                $envelope = $this->prepareEnvelopeForSignature->handle($document, $coSigners, $batchId);
                $senderViewUrl = $this->prepareEnvelopeForSignature->senderViewUrlFor($envelope);
            } catch (Throwable $e) {
                report($e);
                $skipped[] = $this->skip($document, 'error');

                continue;
            }

            $prepared[] = [
                'document_id' => $document->id,
                'document_name' => $document->name,
                'envelope_id' => $envelope->public_id,
                'sender_view_url' => $senderViewUrl,
            ];
        }

        return ['prepared' => $prepared, 'skipped' => $skipped];
    }

    /**
     * A document already has an "active" envelope — one that must not be
     * touched again — whenever any of its envelopes is genuinely in flight
     * on DocuSign's side: sent, viewed, or partially_signed. Terminal
     * statuses (voided/declined/expired/completed) are *not* active and
     * stay eligible: re-preparing after a void is an existing, supported
     * flow on the single-document path — see .claude/rules/signature.md.
     *
     * **`draft` is deliberately excluded from "active" too**, even though
     * it's non-terminal. `PrepareEnvelopeForSignature::handle()` creates the
     * DocuSign envelope in `draft` status immediately — before the tenant
     * has tagged a single field or clicked Send in the Sender View iframe —
     * so a "Prepare All" click that gets interrupted (closed tab, "I'll do
     * the rest later", browser crash) leaves behind draft envelopes for
     * every document it reached. Treating those as "active" left no way
     * back into the Sender View at all: the row's own "Prepare for
     * Signature" button hides for anything non-terminal, and re-running
     * `handle()` for a draft is exactly what its own idempotency exists
     * for (it reuses the existing DocuSign draft rather than creating a
     * duplicate, live-checking DocuSign's own status first so a draft that
     * actually *did* get sent behind our back throws instead of silently
     * reopening a stale editor). Excluding `draft` here is what actually
     * lets a tenant resume an abandoned "Prepare All" batch instead of
     * permanently seeing "Nothing to prepare — every eligible document
     * already has an envelope in progress."
     */
    private function hasActiveEnvelope(Document $document): bool
    {
        $activeStatuses = [EnvelopeStatus::Sent, EnvelopeStatus::Viewed, EnvelopeStatus::PartiallySigned];

        return $document->envelopes->contains(fn ($envelope) => in_array($envelope->status, $activeStatuses, true));
    }

    /**
     * @return array{document_id: int, document_name: string, reason: string}
     */
    private function skip(Document $document, string $reason): array
    {
        return [
            'document_id' => $document->id,
            'document_name' => $document->name,
            'reason' => $reason,
        ];
    }
}
