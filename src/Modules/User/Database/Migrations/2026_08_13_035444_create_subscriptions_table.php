<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            // One subscription per provider for MVP — a provider re-subscribing
            // after cancellation updates this row rather than creating a new one.
            // Revisit if/when subscription history (not just current state) is
            // needed.
            $table->foreignId('provider_id')->unique()->constrained('providers')->cascadeOnDelete();
            $table->enum('plan', ['starter', 'professional', 'firm']);
            $table->enum('status', ['trialing', 'active', 'past_due', 'canceled', 'expired'])->default('trialing');
            $table->timestamp('trial_ends_at')->nullable();
            // Stripe's current billing-period end, once a paid subscription exists.
            // Null throughout the trial and for any provider who never converts.
            $table->timestamp('current_period_ends_at')->nullable();
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('stripe_subscription_id')->nullable()->unique();
            // Which Stripe Price the row is billed against — not the same axis as
            // `plan`: a plan can have monthly/annual prices, so this is looked up
            // from `plan` (+ billing interval) rather than the other way around.
            $table->string('stripe_price_id')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
