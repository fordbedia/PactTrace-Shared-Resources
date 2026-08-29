<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lightweight "your inbox changed" signal — a new message landed, a
 * thread was archived, or a thread was marked read. Broadcast on BOTH:
 *
 *  - `messages.inbox.{providerId}` — the provider's staff (authorized in
 *    backend/routes/channels.php against MessageThreadPolicy::viewAny).
 *  - `messages.client.{clientId}` — the one client the changed thread is
 *    with, when `$clientId` is supplied. This is the portal-side analogue
 *    of the provider inbox channel: a client-role user cannot join the
 *    provider channel (by design), so without this the client portal has
 *    no inbox-wide nudge and a provider -> client message only lands after
 *    a manual refetch. Same fan-out shape, narrowed to one client.
 *
 * Deliberately carries no unread count: "unread for me" depends on which
 * user is asking (`sender_id != me`), and each channel has more than one
 * possible subscriber, so a single number in the payload would be wrong
 * for some of them. Each client re-reads GET /messages/unread-count (and
 * re-fetches the thread list) on receipt instead — the count stays a
 * single server-computed source of truth. `thread_id` is included only as
 * a hint for optimistic UI.
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
        public readonly ?int $clientId = null,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('messages.inbox.' . $this->providerId)];

        if ($this->clientId !== null && $this->clientId > 0) {
            $channels[] = new PrivateChannel('messages.client.' . $this->clientId);
        }

        return $channels;
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
