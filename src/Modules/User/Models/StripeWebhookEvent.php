<?php

namespace PactTrackSDK\SharedResources\Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Idempotency log for inbound Stripe webhook deliveries — see the
 * create_stripe_webhook_events_table migration and
 * Infrastructure\Repositories\Eloquent\EloquentStripeWebhookEventRepository
 * (`firstOrCreate` on `stripe_event_id`, same idempotency shape as
 * Signature\Models\SignatureWebhookEvent).
 */
class StripeWebhookEvent extends Model
{
    protected $fillable = [
        'stripe_event_id',
        'event_type',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
