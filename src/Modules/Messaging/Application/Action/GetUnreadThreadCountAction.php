<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Action;

use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Repository\MessageRepository;

/**
 * Backs GET /api/v1/messages/unread-count — the single figure behind both
 * the "Unread" tab pill on /dashboard/messages and the sidebar "Messages"
 * badge. One source of truth: the sidebar count and the Unread tab's own
 * contents are derived from the same repository query, so they can never
 * disagree.
 */
class GetUnreadThreadCountAction
{
    public function __construct(private readonly MessageRepository $messages)
    {
    }

    public function handle(int $providerId, int $currentUserId): int
    {
        return $this->messages->countUnreadThreadsForProvider($providerId, $currentUserId);
    }
}
