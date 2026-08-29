<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Action;

use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\SendMessageData;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Repository\MessageRepository;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;

/**
 * Starts a NEW conversation — the staff New Message modal
 * (/dashboard/messages) and the portal staff-contact directory both land
 * here. Resolves-or-opens the one thread for this
 * (matter, staff member, subject) and puts the first message on it.
 *
 * "Resolve-or-open" (not always-create): re-using an identical subject
 * with the same staffer on the same matter continues that thread rather
 * than forking a duplicate — mirrors the DB's
 * `message_threads_scope_subject_unique` key. Replying into a thread the
 * user already has open is ReplyToThreadAction, not this.
 */
class SendMessageAction
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly AppendMessageToThread $append,
    ) {
    }

    public function handle(SendMessageData $data): Message
    {
        $thread = $this->messages->firstOrCreateThread(
            providerId: $data->provider_id,
            matterId: $data->matter_id,
            staffUserId: $data->staff_user_id,
            clientId: $data->client_id,
            subject: $data->subject,
        );

        return $this->append->handle(
            thread: $thread,
            senderId: $data->sender_id,
            body: $data->body,
            attachments: $data->attachments,
        );
    }
}
