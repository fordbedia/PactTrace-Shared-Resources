<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services\PortalDocumentStatusMapper;

/**
 * A `Document` as the client portal's Documents table renders it — see
 * .claude/rules/document.md, "Document Deletion & Archival Rules" for the
 * real status lifecycle this replaces the frontend's old hardcoded
 * `buildDocuments()` sample rows with.
 *
 * @mixin \PactTrackSDK\SharedResources\Modules\Document\Models\Document
 */
class PortalDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = PortalDocumentStatusMapper::map($this->status);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $status['key'],
            'status_label' => $status['label'],
            'shared_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'shared_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
