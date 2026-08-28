<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Action;

use PactTrackSDK\SharedResources\Modules\Messaging\Events\InboxUpdated;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;

/**
 * Backs POST /api/v1/messages/threads/{thread}/read — called when a staff
 * user opens a thread on /dashboard/messages. Stamps every message the
 * user did not send that has no `read_at` (MessageThread::markReadFor),
 * which is what drops the thread out of the "Unread" tab and decrements
 * the sidebar badge, then nudges the provider's other open inboxes so
 * their badge/list catch up without a reload.
 *
 * The controller has already resolved and authorized (`view`) the thread.
 */
class MarkThreadReadAction
{
    public function handle(MessageThread $thread, int $userId): MessageThread
    {
        $thread->markReadFor($userId);

        broadcast(new InboxUpdated((int) $thread->provider_id, $thread->id));

        return $thread;
    }
}
