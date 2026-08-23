<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The envelope detail view's whole payload
 * (/dashboard/signatures/matter/{matterId}) — see .claude/rules/signature.md.
 * Assembled by GetMatterEnvelopeDetail, which is responsible for eager
 * loading `document.matter`, `document.uploader`, `client`, `provider` and
 * `signers` on `$this->resource` before this resource ever renders — no
 * relation here is queried lazily. `audit_trail` is deliberately not built
 * in here: it comes back from MatterActivityFeedBuilder::buildForEnvelope(),
 * which the controller merges on via ->additional() — a Resource resolving
 * its own dependencies out of the container is a smell this codebase
 * otherwise avoids (DocumentResource, MatterResource, etc. are all plain
 * data mappers), so this class stays one too.
 *
 * @mixin \PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope
 */
class EnvelopeDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $signers = $this->signers;
        $completedCount = $signers->filter(fn ($signer) => $signer->status === 'signed')->count();
        $totalCount = $signers->count();

        return [
            'id' => $this->public_id,
            'status' => $this->status?->value,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'document' => [
                'id' => $this->document?->id,
                'name' => $this->document?->name,
                'mime_type' => $this->document?->mime_type,
                'size' => $this->document?->size,
            ],
            'matter' => $this->document?->matter === null ? null : [
                'id' => $this->document->matter->id,
                'name' => $this->document->matter->name,
            ],
            'client' => $this->client === null ? null : [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'email' => $this->client->email,
            ],
            /**
             * Envelope carries no "sent by" column of its own — the
             * closest real fact PactTrack tracks is who uploaded the
             * document this envelope was prepared from.
             */
            'prepared_by' => $this->document?->uploader?->name,
            'signers' => SignerResource::collection($signers),
            'completed_count' => $completedCount,
            'total_count' => $totalCount,
            'completion_percentage' => $totalCount === 0 ? 0 : (int) round($completedCount / $totalCount * 100),
            /**
             * Every signer PrepareEnvelopeForSignature creates today shares
             * routing_order 1 (see .claude/rules/signature.md, "Every
             * recipient is a DocuSign Signer") — this reads that real
             * column rather than hardcoding "Parallel", so it self-corrects
             * if routed/sequential signing is ever built.
             */
            'signing_order' => $signers->pluck('routing_order')->unique()->count() <= 1 ? 'Parallel' : 'Sequential',
        ];
    }
}
