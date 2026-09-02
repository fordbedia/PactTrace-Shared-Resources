<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's per-type notification overrides.
 *
 * One row per (user, notification_type). A row exists only once the user has
 * changed something away from the type's default — the
 * `Notification::isset('key')` helper and the `/notification` screen both fall
 * back to `notification_types.default_*` when no row is present, so an
 * untouched account needs zero rows here.
 *
 * `email` / `in_app` / `sms` mirror the three channel columns on
 * `notification_types`; only `email` is writable from the UI today, the other
 * two are carried so the channels can ship without a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('notification_type_id')
                ->constrained('notification_types')
                ->cascadeOnDelete();

            $table->boolean('email')->default(true);
            $table->boolean('in_app')->default(true);
            $table->boolean('sms')->default(false);

            $table->timestamps();

            // At most one override row per user per type.
            $table->unique(['user_id', 'notification_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
