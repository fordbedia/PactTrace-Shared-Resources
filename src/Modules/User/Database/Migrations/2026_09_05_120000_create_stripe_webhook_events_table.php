<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency log for inbound Stripe webhook deliveries — same role as
     * `signature_webhook_events.payload_hash` in the Signature module, keyed
     * on Stripe's own `event.id` instead of a payload hash (Stripe already
     * guarantees a stable id per event, so hashing the body would only be a
     * more expensive way to derive the same fact). `firstOrCreate` on
     * `stripe_event_id` is what lets a redelivered event be recognised and
     * skipped rather than reprocessed.
     */
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            // Stripe's `event.type` (e.g. `customer.subscription.updated`) —
            // kept only for debugging/observability, nothing branches on it
            // once the row exists.
            $table->string('event_type')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
