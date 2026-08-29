<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Action;

use Illuminate\Support\Facades\Log;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\InboxUpdated;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use Throwable;

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

        // The real-time nudge is best-effort. Marking a thread read has
        // already committed above; a broadcast transport failure (Reverb
        // unreachable, a misconfigured connection, a synchronous driver
        // erroring) must never bubble out of this action and turn
        // "open a conversation" into a 500 — which would leave the client's
        // POST /read hanging and the "Unread" badge stuck showing. On
        // success the frontend re-reads GET /messages/unread-count itself,
        // so a dropped nudge only costs other open tabs a manual refresh,
        // never this user's own badge. Same contract as
        // RecordSignatureCompletionUseCase::notifyClient().
        try {
            broadcast(new InboxUpdated((int) $thread->provider_id, $thread->id, (int) $thread->client_id));
        } catch (Throwable $e) {
            Log::warning('MarkThreadReadAction: inbox broadcast failed', [
                'thread_id' => $thread->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return $thread;
    }
}
