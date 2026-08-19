<?php

namespace PactTrackSDK\SharedResources\Modules\User\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PactTrackSDK\SharedResources\Modules\User\Database\Factories\SubscriptionFactory;

/**
 * The authoritative billing/plan record for a Provider (tenant). Created at
 * sign-up with `status: trialing`; `stripe_*` columns stay null until a trial
 * converts to a paid subscription.
 *
 * `Provider::plan` / `Provider::trial_ends_at` remain as a denormalized read
 * cache kept in sync from the Application/UseCases layer (same pattern as
 * `MessageThread::last_message_at`) — this row is the source of truth, those
 * columns exist only so code that already reads `provider.plan` doesn't need
 * to join through here. See .claude/rules/user.md.
 */
class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'plan',
        'status',
        'trial_ends_at',
        'current_period_ends_at',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_price_id',
        'canceled_at',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_ends_at' => 'datetime',
        'canceled_at' => 'datetime',
    ];

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
