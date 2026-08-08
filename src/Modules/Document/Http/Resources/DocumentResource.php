<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \PactTraceSDK\SharedResources\Modules\Document\Models\Document
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
            'matter_id' => $this->matter_id,
            'client_id' => $this->client_id,
            'folder_id' => $this->folder_id,
            'uploaded_by' => $this->uploaded_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
