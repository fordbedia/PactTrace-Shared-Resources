<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.google_id` / `users.microsoft_id` — the external identity a user has
 * linked via "Continue with Google" / "Continue with Microsoft" on /sign-in
 * and /sign-up. Both nullable (most accounts still sign in with a password
 * only) and independently unique (one PactTrack account per external
 * identity; a user is free to have both linked at once, see
 * AuthenticateViaOAuth). Neither is exposed on UserResource — they're
 * write-only linkage columns, not something the frontend reads.
 *
 * Regenerate the test DB snapshot after pulling this — see the top-level
 * CLAUDE.md, "Unit testing".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('google_id')->nullable()->unique()->after('avatar_path');
            $table->string('microsoft_id')->nullable()->unique()->after('google_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['google_id', 'microsoft_id']);
        });
    }
};
