<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A **scheduled plan change** the tenant kicked off in the Stripe Customer
 * Portal (a downgrade there is not applied immediately — Stripe defers it to
 * the end of the current billing period via a subscription schedule).
 *
 *  - `pending_plan`              the tier it will move TO (nullable — the
 *                               normal state is "no pending change").
 *  - `pending_plan_effective_at` when the schedule executes (the current
 *                               period's end).
 *
 * Both are written from the `subscription_schedule.*` webhooks
 * (SyncScheduledPlanChange) and cleared when the schedule executes
 * (SyncSubscriptionFromStripe, once the live plan catches up) or is released.
 * PactTrack enforces the *pending* (lower) plan's limits immediately — see
 * Domain\Services\EffectivePlan and .claude/rules/plan.md, "Pending
 * downgrade".
 *
 * `current_period_starts_at` is added alongside because the e-signatures
 * flow limit (`maxEnvelopesPerMonth`) must be counted per **Stripe billing
 * cycle**, not per calendar month — see EloquentPlanUsageReader.
 *
 * Regenerate the test DB snapshot after pulling this — see the top-level
 * CLAUDE.md, "Unit testing".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('pending_plan')->nullable()->after('plan');
            $table->timestamp('pending_plan_effective_at')->nullable()->after('pending_plan');
            $table->timestamp('current_period_starts_at')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['pending_plan', 'pending_plan_effective_at', 'current_period_starts_at']);
        });
    }
};
