<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue of things a user can be notified about — one row per
 * "notification type" (`new_doc_uploaded`, `new_message_from_client`, ...).
 *
 * This is the reference table the `Notification::isset('key')` helper and the
 * `/notification` preferences screen both read. It is populated by
 * `NotificationTypeSeeder` (idempotent, enum-style — the seeder is the source
 * of truth, this table is a projection of it), the same way
 * `RolePermissionSeeder` owns the permission catalogue.
 *
 * `default_email` / `default_in_app` / `default_sms` are the values that apply
 * to a user who has never touched their preferences (no `user_notifications`
 * row). `*_locked` marks a channel the user cannot turn off — e.g. Security
 * alerts on Email — which the screen renders as a "Required" lock.
 *
 * Only Email is surfaced in the UI today; `in_app` / `sms` columns exist so
 * the schema doesn't need changing when those channels ship.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_types', function (Blueprint $table): void {
            $table->id();

            // Stable machine key used by code: Notification::isset('new_doc_uploaded').
            $table->string('key')->unique();

            // Screen-facing wording (ported from Dashboard/Notifications.html).
            $table->string('label');
            $table->string('description');

            // The heading a row sits under on the preferences screen
            // ("Matters & Documents", "Messages", "Account & Billing").
            $table->string('group');

            // Manual ordering within a group.
            $table->unsignedSmallInteger('position')->default(0);

            // Defaults for a user with no override row.
            $table->boolean('default_email')->default(true);
            $table->boolean('default_in_app')->default(true);
            $table->boolean('default_sms')->default(false);

            // Channels the user is not allowed to disable.
            $table->boolean('email_locked')->default(false);
            $table->boolean('in_app_locked')->default(false);
            $table->boolean('sms_locked')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_types');
    }
};
