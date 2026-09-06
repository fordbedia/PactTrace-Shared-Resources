<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent;

use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\StripeWebhookEventRepository;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\BaseRepository;
use PactTrackSDK\SharedResources\Modules\User\Models\StripeWebhookEvent;

class EloquentStripeWebhookEventRepository extends BaseRepository implements StripeWebhookEventRepository
{
    public function makeModel(): string
    {
        return StripeWebhookEvent::class;
    }

    public function recordIfNew(string $stripeEventId, string $eventType): bool
    {
        $event = $this->model->query()->firstOrCreate(
            ['stripe_event_id' => $stripeEventId],
            ['event_type' => $eventType],
        );

        return $event->wasRecentlyCreated;
    }

    public function markProcessed(string $stripeEventId): void
    {
        $this->model->query()
            ->where('stripe_event_id', $stripeEventId)
            ->update(['processed_at' => now()]);
    }
}
