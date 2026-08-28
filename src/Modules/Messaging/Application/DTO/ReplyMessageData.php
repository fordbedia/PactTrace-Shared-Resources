<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO;

use Illuminate\Http\UploadedFile;

/**
 * The input to ReplyToThreadAction — one message posted into an existing
 * thread (POST /api/v1/messages/threads/{thread} on the staff side, or the
 * portal's mirror route). The thread itself is resolved and authorised by
 * the controller and passed separately; this only carries the message.
 *
 * @param list<UploadedFile> $attachments
 */
final readonly class ReplyMessageData
{
    /**
     * @param list<UploadedFile> $attachments
     */
    public function __construct(
        public int $sender_id,
        public string $body,
        public array $attachments = [],
    ) {
    }
}
