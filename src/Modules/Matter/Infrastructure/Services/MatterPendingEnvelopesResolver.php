<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services;

use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;

/**
 * Every non-terminal Envelope across every Document on a Matter — what the
 * client portal's matter detail view renders one "Review & Sign" action per
 * (see .claude/rules/matter.md, "Documents on this matter" and
 * .claude/rules/signature.md). A Matter is reused across a whole engagement,
 * so more than one Document — each with its own Envelope — can legitimately
 * be pending signature on the same Matter at once; this is why the portal no
 * longer sources its action card from the unscoped `GET /api/signature/pending`
 * (every pending envelope across every matter the client has), only from
 * this matter's own already-eager-loaded documents/envelopes.
 *
 * "Non-terminal" mirrors `Envelope::scopePendingForClient()`'s own
 * completed/declined/voided/expired exclusion exactly, via
 * `EnvelopeStatus::isTerminal()` — the same definition, not a second one
 * that could drift from it.
 *
 * Callers must eager-load `documents.envelopes` on `$matter` — this class
 * never queries on its own, same contract as `MatterActivityFeedBuilder`.
 */
final class MatterPendingEnvelopesResolver
{
    /**
     * @return list<array{envelope_id: string, document_name: string, status: string, sent_at: ?string}>
     */
    public function resolve(Matter $matter): array
    {
        $entries = [];

        foreach ($matter->documents as $document) {
            foreach ($document->envelopes as $envelope) {
                if ($envelope->status->isTerminal()) {
                    continue;
                }

                $entries[] = [
                    'envelope_id' => $envelope->public_id,
                    'document_name' => $document->name,
                    'status' => $envelope->status->value,
                    'sent_at' => $envelope->sent_at?->toIso8601String(),
                ];
            }
        }

        return $entries;
    }
}
