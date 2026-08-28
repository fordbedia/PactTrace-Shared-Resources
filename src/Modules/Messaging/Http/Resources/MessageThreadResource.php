<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Client\Http\Resources\ClientResource;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;

/**
 * A message thread — the inbox row (/dashboard/messages) and the
 * conversation header. Allow-list, same shape rules as MatterResource.
 *
 * `messages` is only present when the caller eager-loaded the
 * `conversation` relation (the thread-detail endpoint). The flat
 * `*_name` / `last_message_preview` / `unread` fields back the inbox row
 * without pulling those relations in full — they come from the listing
 * query's `with(['client','matter','latestMessage'])` +
 * `withCount('unread_messages_count')`, so a response that didn't load
 * them simply omits them (`whenLoaded`) rather than issuing queries here.
 *
 * @mixin MessageThread
 */
class MessageThreadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'client_id' => $this->client_id,
            'matter_id' => $this->matter_id,
            'subject' => $this->subject,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // Inbox-row fields — flat, whenLoaded, no queries.
            'client_name' => $this->whenLoaded('client', fn () => $this->client?->name),
            'client_company' => $this->whenLoaded('client', fn () => $this->client?->company_name),
            'matter_name' => $this->whenLoaded('matter', fn () => $this->matter?->name),
            'matter_public_id' => $this->whenLoaded('matter', fn () => $this->matter?->public_id),
            'last_message_preview' => $this->whenLoaded('latestMessage', fn () => $this->latestMessage?->body),
            'unread' => (bool) ($this->unread_messages_count ?? 0),

            'client' => ClientResource::make($this->whenLoaded('client')),
            'messages' => MessageResource::collection($this->whenLoaded('conversation')),
        ];
    }
}
