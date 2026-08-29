<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Action;

use PactTrackSDK\SharedResources\Modules\Messaging\Events\InboxUpdated;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;

/**
 * Backs DELETE /api/v1/messages/threads/{thread} — the row-level "Archive"
 * action on /dashboard/messages. The thread is a soft delete
 * (MessageThread::archive() -> $thread->delete()): it drops out of both
 * inbox tabs immediately, and its row + messages stay in the database for
 * the audit trail (MessageThread::withTrashed() still finds it).
 *
 * The controller has already resolved the thread by route-model binding
 * and authorized `archive` on it (tenant scoping included), so this only
 * performs the transition and nudges the provider's other open inboxes.
 */
class ArchiveThreadAction
{
    public function handle(MessageThread $thread): void
    {
        $thread->archive();

        broadcast(new InboxUpdated((int) $thread->provider_id, $thread->id, (int) $thread->client_id));
    }
}
