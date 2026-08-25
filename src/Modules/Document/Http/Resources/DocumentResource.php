<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \PactTrackSDK\SharedResources\Modules\Document\Models\Document
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'version' => $this->version,
            'status' => $this->status?->value,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'matter_id' => $this->matter_id,
            'matter_name' => $this->whenLoaded('matter', fn () => $this->matter?->name),
            /**
             * The document's matter's own public_id (a ULID) — needed by the
             * upload-success modal on /dashboard/documents to link straight
             * to `/dashboard/matters/{public_id}` (the Matter Detail route,
             * see .claude/rules/matter.md) without a second request.
             * `whenLoaded` like matter_name above; DocumentController::store()
             * loads `matter` for exactly this before returning its response —
             * every other caller of this resource already eager-loads it
             * (EloquentDocumentRepository::forProvider/forFolders/forMatter).
             */
            'matter_public_id' => $this->whenLoaded('matter', fn () => $this->matter?->public_id),
            'client_id' => $this->client_id,
            'client_name' => $this->whenLoaded('client', fn () => $this->client?->name),
            'client_email' => $this->whenLoaded('client', fn () => $this->client?->email),
            'folder_id' => $this->folder_id,
            /**
             * The document's current envelope, if it has ever been prepared
             * for signature — a Document can accumulate more than one
             * Envelope over its lifetime (e.g. voided, then re-prepared),
             * so "current" means the most recently created one. Exposed by
             * public_id (never the internal id) for the same reason
             * Envelope::getRouteKeyName() exists — see
             * .claude/rules/signature.md. Backs the "View Signature" link
             * on the Matter Detail view's "Documents on this matter"
             * section (.claude/rules/matter.md).
             */
            'envelope_public_id' => $this->whenLoaded(
                'envelopes',
                fn () => $this->envelopes->sortByDesc('created_at')->first()?->public_id
            ),
            /**
             * The same most-recent envelope's own status (draft/sent/.../
             * voided/etc — see EnvelopeStatus), alongside envelope_public_id
             * above. Document.status is NOT a reliable proxy for this: it
             * only mirrors Sent/PartiallySigned/Completed/Voided (see
             * RecordSignatureCompletionUseCase::DOCUMENT_STATUS_MAP), so a
             * declined/expired/still-draft envelope leaves Document.status
             * stuck at whatever it was — exactly the gap that would make the
             * Matter Detail page's "Prepare for Signature" vs "View
             * Signature" choice wrong for those statuses. See
             * .claude/rules/matter.md.
             */
            'envelope_status' => $this->whenLoaded(
                'envelopes',
                fn () => $this->envelopes->sortByDesc('created_at')->first()?->status?->value
            ),
            'uploaded_by' => $this->uploaded_by,
            'uploaded_by_name' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
