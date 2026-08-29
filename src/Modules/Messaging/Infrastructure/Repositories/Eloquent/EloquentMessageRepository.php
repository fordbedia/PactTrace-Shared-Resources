<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Infrastructure\Repositories\Eloquent;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Repository\MessageRepository;
use PactTrackSDK\SharedResources\Modules\Messaging\Infrastructure\Repositories\BaseRepository;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageAttachment;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;

/**
 * Eloquent adapter for MessageRepository. Modeled directly on the Document
 * module's EloquentFolderRepository — same BaseRepository (-> SDK
 * RepositoryLayer) base, same thin method-per-need shape, same
 * makeModel() convention. `$this->model` is a MessageThread (the module's
 * aggregate root); Message/MessageAttachment are reached and created
 * through it.
 */
class EloquentMessageRepository extends BaseRepository implements MessageRepository
{
    public function findThread(int $threadId): ?MessageThread
    {
        return $this->model->newQuery()->find($threadId);
    }

    public function firstOrCreateThread(
        int $providerId,
        int $matterId,
        int $staffUserId,
        int $clientId,
        string $subject,
    ): MessageThread {
        return $this->model->newQuery()->firstOrCreate(
            [
                // Mirrors message_threads_scope_subject_unique. client_id
                // is derived from the matter, so it is stored, not matched.
                'provider_id' => $providerId,
                'matter_id' => $matterId,
                'staff_user_id' => $staffUserId,
                'subject' => $subject,
            ],
            [
                'client_id' => $clientId,
                'last_message_at' => now(),
            ],
        );
    }

    public function createMessage(int $threadId, int $senderId, string $body): Message
    {
        return Message::query()->create([
            'thread_id' => $threadId,
            'sender_id' => $senderId,
            'body' => $body,
        ]);
    }

    public function createAttachment(
        int $messageId,
        string $fileName,
        ?string $s3Path,
        ?string $mimeType = null,
        ?int $size = null,
        ?int $documentId = null,
    ): MessageAttachment {
        return MessageAttachment::query()->create([
            'message_id' => $messageId,
            'document_id' => $documentId,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'size' => $size,
            's3_path' => $s3Path,
        ]);
    }

    public function paginateThreadsForProvider(
        int $providerId,
        int $currentUserId,
        int $perPage,
        ?int $page,
    ): LengthAwarePaginator {
        return $this->paginate(
            $this->inboxQuery($providerId, $currentUserId),
            $perPage,
            ['*'],
            'page',
            $page,
        );
    }

    public function paginateUnreadThreadsForProvider(
        int $providerId,
        int $currentUserId,
        int $perPage,
        ?int $page,
    ): LengthAwarePaginator {
        return $this->paginate(
            $this->inboxQuery($providerId, $currentUserId)->withUnreadFor($currentUserId),
            $perPage,
            ['*'],
            'page',
            $page,
        );
    }

    public function countUnreadThreadsForProvider(int $providerId, int $currentUserId): int
    {
        return $this->model->newQuery()
            ->forProvider($providerId)
            ->withUnreadFor($currentUserId)
            ->count();
    }

    public function threadsForMatter(int $providerId, int $matterId, int $currentUserId): Collection
    {
        return $this->model->newQuery()
            ->forProvider($providerId)
            ->forMatter($matterId)
            ->with(['staffMember', 'latestMessage'])
            ->withCount($this->unreadCountAlias($currentUserId))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The shared base query for both inbox tabs: this provider's
     * non-archived threads, newest activity first, with everything the
     * inbox row renders eager-loaded and the per-row unread flag as a
     * withCount alias.
     */
    private function inboxQuery(int $providerId, int $currentUserId): Builder
    {
        return $this->model->newQuery()
            ->forProvider($providerId)
            ->with(['client', 'matter', 'staffMember', 'latestMessage'])
            ->withCount($this->unreadCountAlias($currentUserId))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
    }

    /**
     * `unread_messages_count` = messages on the thread the given user did
     * not send that have no `read_at`. One definition, reused by every
     * listing query so the inbox, the portal widget and the badge can't
     * disagree.
     *
     * @return array<string, callable(Builder): void>
     */
    private function unreadCountAlias(int $currentUserId): array
    {
        return [
            'messages as unread_messages_count' => function (Builder $messages) use ($currentUserId): void {
                $messages->where('sender_id', '!=', $currentUserId)->whereNull('read_at');
            },
        ];
    }

    public function messagesForMatter(int $providerId, int $matterId): Collection
    {
        return Message::query()
            ->whereHas('thread', function ($query) use ($providerId, $matterId): void {
                $query->where('provider_id', $providerId)
                    ->where('matter_id', $matterId);
            })
            ->with(['sender', 'attachments'])
            ->oldest()
            ->get();
    }

    public function makeModel(): string
    {
        return MessageThread::class;
    }
}
