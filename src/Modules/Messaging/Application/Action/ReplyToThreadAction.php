<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Action;

use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\ReplyMessageData;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use RuntimeException;

/**
 * Posts a reply into an EXISTING thread — POST /api/v1/messages/threads/{thread}
 * (staff) and its portal mirror. The controller has already resolved the
 * thread by route-model binding and authorised MessageThreadPolicy::reply
 * (which is where the "only the thread's own staff_user_id, or its client,
 * may reply" rule lives). This only writes the message.
 */
class ReplyToThreadAction
{
    public function __construct(private readonly AppendMessageToThread $append)
    {
    }

    public function handle(MessageThread $thread, ReplyMessageData $data): Message
    {
        // An archived thread is a soft delete — route-model binding won't
        // resolve one, so this is belt-and-braces for a caller that passed
        // a withTrashed() instance.
        if ($thread->trashed()) {
            throw new RuntimeException('Cannot reply to an archived thread.');
        }

        return $this->append->handle(
            thread: $thread,
            senderId: $data->sender_id,
            body: $data->body,
            attachments: $data->attachments,
        );
    }
}
