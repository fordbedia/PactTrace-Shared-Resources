<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Action;

use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Repository\MessageRepository;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;

/**
 * Every non-archived thread on one matter, newest activity first — backs
 * the client portal's per-matter messaging widget (left pane). Thin: the
 * provider scoping, eager-loading and unread annotation live in the
 * repository (EloquentMessageRepository::threadsForMatter).
 *
 * The caller (PortalMessagingController) has already authorised `view` on
 * the Matter; passing provider_id through keeps the query tenant-scoped
 * too — a gate alone is never sufficient for an index.
 *
 * @return Collection<int, MessageThread>
 */
class ListMatterThreadsAction
{
    public function __construct(private readonly MessageRepository $messages)
    {
    }

    /**
     * @return Collection<int, MessageThread>
     */
    public function handle(int $providerId, int $matterId, int $currentUserId): Collection
    {
        return $this->messages->threadsForMatter($providerId, $matterId, $currentUserId);
    }
}
