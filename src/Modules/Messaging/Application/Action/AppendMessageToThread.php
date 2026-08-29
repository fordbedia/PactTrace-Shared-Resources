<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Action;

use Illuminate\Http\UploadedFile;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Repository\MessageRepository;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\InboxUpdated;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\NewMessage;
use PactTrackSDK\SharedResources\Modules\Messaging\Infrastructure\Upload\MessageAttachmentStorageService;
use PactTrackSDK\SharedResources\Modules\Messaging\Jobs\SendStaffUnreadMessageReminder;
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
 *   5. when the sender is the client (not the thread's staff member),
 *      schedule the 5-minute "still unread?" reminder for that staffer —
 *      see SendStaffUnreadMessageReminder
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
                mimeType: $file->getClientMimeType(),
                size: $file->getSize() !== false ? (int) $file->getSize() : null,
            );
        }

        $thread->recordActivity($message->created_at ?? now());

        broadcast(new NewMessage($message))->toOthers();
        broadcast(new InboxUpdated((int) $thread->provider_id, $thread->id, (int) $thread->client_id));

        // A thread is exactly one staff member + one client (no group threads),
        // so a sender who isn't the thread's staff member IS the client. Only
        // that direction gets the "your client's message is still unread"
        // nudge — a staff -> client message never does.
        if ($senderId !== (int) $thread->staff_user_id) {
            SendStaffUnreadMessageReminder::dispatch((int) $thread->id, (int) $message->id)
                ->delay(now()->addMinutes(5));
        }

        return $message->load(['sender', 'attachments']);
    }
}
