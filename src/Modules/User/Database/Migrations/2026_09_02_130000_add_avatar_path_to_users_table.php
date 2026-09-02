<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.avatar_path` — the storage key of a user's uploaded profile photo,
 * shown on `/profile` and throughout the app shell (with an initials fallback
 * when it's null, which it is until the user uploads one).
 *
 * There is deliberately NO `users.disk` column: which disk avatars live on is
 * one configured value (`config('filesystems.avatar_disk')`), not a per-row
 * choice — same pattern the Document module already uses for
 * `filesystems.document_disk`. The frontend never sees this path; it sees the
 * public URL resolved from it (`UserResource.avatar_url`).
 *
 * Regenerate the test DB snapshot after pulling this — see the top-level
 * CLAUDE.md, "Unit testing".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('avatar_path');
        });
    }
};
