<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user read state for the notification bell (AppShell's Topbar) — see
 * .claude/rules/notification.md. The bell's feed is a live read model over
 * `audit_logs` (same rows /dashboard/audit-log lists), not a separate event
 * log; this table is only the "has this user already seen/read/cleared this
 * row" fact, one row per (user, audit_log) pair the user has acted on.
 *
 * A row here means "read" — there is no separate boolean, its mere existence
 * is the source of truth NotificationFeedController checks against. Clearing
 * the whole feed inserts one row per currently-visible audit log id for that
 * user, same mechanism as reading one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('audit_log_id')->constrained('audit_logs')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['user_id', 'audit_log_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
    }
};
