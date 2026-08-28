<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageAttachment;

/**
 * One file attached to a message. `s3_path` is deliberately not exposed —
 * it is an internal storage key; a download surface (signed URL) is a
 * separate follow-up, same as for documents (see .claude/rules/document.md).
 *
 * @mixin MessageAttachment
 */
class MessageAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'message_id' => $this->message_id,
            'document_id' => $this->document_id,
            'file_name' => $this->file_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
