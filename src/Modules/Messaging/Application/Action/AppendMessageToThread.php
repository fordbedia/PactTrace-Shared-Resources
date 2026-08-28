<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Action;

use Illuminate\Http\UploadedFile;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Repository\MessageRepository;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\InboxUpdated;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\NewMessage;
use PactTrackSDK\SharedResources\Modules\Messaging\Infrastructure\Upload\MessageAttachmentStorageService;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;

/**
 * The single "put one message onto a thread" step, shared by
 * SendMessageAction (new thread) and ReplyToThreadAction (existing
 * thread) so the persistence + broadcast sequence lives in exactly one
 * place:
 *
 *   1. persist the Message
 *   2. store each attachment's bytes (reusing the Document module's
 *      storage port) and record a MessageAttachment row
 *   3. move the thread's denormalized `last_message_at` forward
 *   4. broadcast NewMessage over Reverb to *other* subscribers on the
 *      thread (the sender already has it in the HTTP response), and
 *      InboxUpdated to the provider's inbox channel so open
 *      /dashboard/messages lists and the sidebar badge refresh in place
 *
 * Callers own thread resolution and authorization; this owns nothing but
 * the write.
 */
class AppendMessageToThread
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly MessageAttachmentStorageService $attachmentStorage,
    ) {
    }

    /**
     * @param list<UploadedFile> $attachments
     */
    public function handle(MessageThread $thread, int $senderId, string $body, array $attachments = []): Message
    {
        $message = $this->messages->createMessage(
            threadId: $thread->id,
            senderId: $senderId,
            body: $body,
        );

        foreach ($attachments as $file) {
            $path = $this->attachmentStorage->store($file, (int) $thread->provider_id);

            $this->messages->createAttachment(
                messageId: $message->id,
                fileName: $file->getClientOriginalName(),
                s3Path: $path,
            );
        }

        $thread->recordActivity($message->created_at ?? now());

        broadcast(new NewMessage($message))->toOthers();
        broadcast(new InboxUpdated((int) $thread->provider_id, $thread->id));

        return $message->load(['sender', 'attachments']);
    }
}
