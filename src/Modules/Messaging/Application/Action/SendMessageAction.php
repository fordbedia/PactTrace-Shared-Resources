<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Action;

use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\SendMessageData;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Repository\MessageRepository;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\InboxUpdated;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\NewMessage;
use PactTrackSDK\SharedResources\Modules\Messaging\Infrastructure\Upload\MessageAttachmentStorageService;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;

/**
 * Orchestration behind the New Message modal's Send button
 * (/dashboard/messages). Same Application/Action shape as the Document
 * module's UploadDocumentAction — the controller calls only this.
 *
 * Steps, in order:
 *   1. Resolve (or open) the thread for this provider + client + matter.
 *   2. Persist the Message.
 *   3. Store each attachment's bytes (reusing the Document module's
 *      storage port) and record a MessageAttachment row.
 *   4. Move the thread's denormalized `last_message_at` forward.
 *   5. Broadcast NewMessage over Reverb to *other* subscribers on the
 *      thread (the sender already has the message in the HTTP response),
 *      and InboxUpdated to the provider's inbox channel so open
 *      /dashboard/messages lists and the sidebar badge refresh in place.
 */
class SendMessageAction
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly MessageAttachmentStorageService $attachmentStorage,
    ) {
    }

    public function handle(SendMessageData $data): Message
    {
        $thread = $this->messages->firstOrCreateThread(
            providerId: $data->provider_id,
            clientId: $data->client_id,
            matterId: $data->matter_id,
            subject: $data->subject,
        );

        $message = $this->messages->createMessage(
            threadId: $thread->id,
            senderId: $data->sender_id,
            body: $data->body,
        );

        foreach ($data->attachments as $file) {
            $path = $this->attachmentStorage->store($file, $data->provider_id);

            $this->messages->createAttachment(
                messageId: $message->id,
                fileName: $file->getClientOriginalName(),
                s3Path: $path,
            );
        }

        $thread->recordActivity($message->created_at ?? now());

        broadcast(new NewMessage($message))->toOthers();
        broadcast(new InboxUpdated($data->provider_id, $thread->id));

        return $message->load(['sender', 'attachments']);
    }
}
