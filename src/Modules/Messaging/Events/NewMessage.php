<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Resources\MessageResource;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;

/**
 * Broadcast when a Message is persisted — the outbound real-time push to
 * other connected clients on the same thread (see
 * .claude/rules/messaging.md, "Real-time transport"). The message itself is
 * created by an ordinary HTTP POST; this event only fans it out over
 * Reverb, and SendMessageAction fires it with `->toOthers()` so the sender
 * (who already has the message in their own response) is not echoed back.
 *
 * Channel authorization lives in backend/routes/channels.php
 * (`messages.thread.{threadId}` -> MessageThreadPolicy::view).
 */
class NewMessage implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly Message $message)
    {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('messages.thread.' . $this->message->thread_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return (new MessageResource(
            $this->message->loadMissing(['sender', 'attachments']),
        ))->resolve();
    }
}
