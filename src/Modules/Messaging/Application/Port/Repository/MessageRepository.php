<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Repository;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageAttachment;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;

/**
 * Persistence port for the Messaging module — same hexagonal shape as the
 * Document module's FolderRepository (its Eloquent adapter,
 * EloquentFolderRepository, is the literal template for
 * EloquentMessageRepository). Application-layer actions depend on this
 * interface, never on Eloquent directly, so the store can be swapped or
 * faked in isolation.
 */
interface MessageRepository
{
    public function findThread(int $threadId): ?MessageThread;

    /**
     * One page of a provider's non-archived threads for the inbox — newest
     * activity first, with `client`, `matter` and `latestMessage`
     * eager-loaded and an `unread_messages_count` withCount alias
     * (messages the given user has not read). Archived (soft-deleted)
     * threads are excluded by the model's SoftDeletes trait.
     *
     * @return LengthAwarePaginator<int, MessageThread>
     */
    public function paginateThreadsForProvider(
        int $providerId,
        int $currentUserId,
        int $perPage,
        ?int $page,
    ): LengthAwarePaginator;

    /**
     * As {@see paginateThreadsForProvider()}, narrowed to threads that hold
     * at least one message the given user has not read — the "Unread" tab.
     *
     * @return LengthAwarePaginator<int, MessageThread>
     */
    public function paginateUnreadThreadsForProvider(
        int $providerId,
        int $currentUserId,
        int $perPage,
        ?int $page,
    ): LengthAwarePaginator;

    /**
     * How many of the provider's non-archived threads hold an unread
     * message for the given user — the single figure behind both the
     * "Unread" tab pill and the sidebar badge.
     */
    public function countUnreadThreadsForProvider(int $providerId, int $currentUserId): int;

    /**
     * The one open thread between a provider and a client for a given
     * matter scope (matter_id may be null — a thread not tied to any
     * matter). Created if it does not exist yet; `subject` is only applied
     * on creation, never overwritten on an existing thread.
     */
    public function firstOrCreateThread(
        int $providerId,
        int $clientId,
        ?int $matterId,
        ?string $subject,
    ): MessageThread;

    public function createMessage(int $threadId, int $senderId, string $body): Message;

    public function createAttachment(
        int $messageId,
        string $fileName,
        ?string $s3Path,
        ?int $documentId = null,
    ): MessageAttachment;

    /**
     * Every message on the given provider's threads for one matter, oldest
     * first, with senders and attachments eager-loaded. Scoped by
     * provider_id in the query itself — a `viewAny`/`view` gate is
     * necessary but not sufficient for an index (see TenantScopedPolicy).
     *
     * @return Collection<int, Message>
     */
    public function messagesForMatter(int $providerId, int $matterId): Collection;
}
