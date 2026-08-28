<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lightweight "your inbox changed" signal for one provider's staff — a
 * new message landed, a thread was archived, or a thread was marked read.
 * Broadcast on `messages.inbox.{providerId}` (authorized in
 * backend/routes/channels.php against MessageThreadPolicy::viewAny, so a
 * provider only ever hears its own inbox).
 *
 * Deliberately carries no unread count: "unread for me" depends on which
 * user is asking (`sender_id != me`), and the channel is per-provider, so
 * a single number in the payload would be wrong for some subscribers.
 * Each client re-reads GET /messages/unread-count (and re-fetches the
 * thread list) on receipt instead — the count stays a single
 * server-computed source of truth. `thread_id` is included only as a hint
 * for optimistic UI.
 *
 * NewMessage (the per-thread event) is unchanged and still fans the
 * message body out on `messages.thread.{threadId}`; this is the separate,
 * inbox-wide nudge.
 */
class InboxUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $providerId,
        public readonly ?int $threadId = null,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('messages.inbox.' . $this->providerId)];
    }

    public function broadcastAs(): string
    {
        return 'inbox.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['thread_id' => $this->threadId];
    }
}
