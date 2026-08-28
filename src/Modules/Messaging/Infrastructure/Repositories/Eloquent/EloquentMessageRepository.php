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
        int $clientId,
        ?int $matterId,
        ?string $subject,
    ): MessageThread {
        return $this->model->newQuery()->firstOrCreate(
            [
                'provider_id' => $providerId,
                'client_id' => $clientId,
                'matter_id' => $matterId,
            ],
            [
                // Applied only when the row is created — an existing
                // thread keeps whatever subject it already had.
                'subject' => $subject,
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
        ?int $documentId = null,
    ): MessageAttachment {
        return MessageAttachment::query()->create([
            'message_id' => $messageId,
            'document_id' => $documentId,
            'file_name' => $fileName,
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
            ->with(['client', 'matter', 'latestMessage'])
            ->withCount(['messages as unread_messages_count' => function (Builder $messages) use ($currentUserId): void {
                $messages->where('sender_id', '!=', $currentUserId)->whereNull('read_at');
            }])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
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
