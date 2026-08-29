<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageAttachment;

/**
 * One file attached to a message. `s3_path` is deliberately never exposed —
 * it is an internal storage key. Downloads go through the dedicated
 * endpoints instead (`GET /api/v1/messages/attachments/{attachment}` on the
 * staff side, `GET /api/v1/portal/message-attachments/{attachment}` on the
 * portal side), both authorised by MessageThreadPolicy::view — so the
 * frontend composes the URL from `id` for whichever surface it is on. See
 * .claude/rules/messaging.md, "Attachment downloads".
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
            'mime_type' => $this->mime_type,
            'size' => $this->size !== null ? (int) $this->size : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
