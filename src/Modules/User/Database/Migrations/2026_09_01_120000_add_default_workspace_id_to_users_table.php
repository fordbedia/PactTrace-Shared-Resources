<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workspace a user lands in on their next sign-in — "whatever I was last
 * using" persisted across sessions.
 *
 * Nullable: a user mid-invitation (no `users` row yet) or a legacy row created
 * before this feature has none, and that is a normal state — the login wiring
 * and RequestWorkspaceContext both treat a null (or stale / cross-tenant /
 * soft-deleted) default exactly like "no default" and fall back to the
 * provider's sole-workspace resolution as before.
 *
 * `nullOnDelete()` covers a *hard* delete of a workspace. Deactivation is a
 * soft delete (Workspace `use SoftDeletes`), which leaves the row — and this
 * FK — intact; every reader validates the target is live + same-tenant rather
 * than trusting the column, so a pointer at a deactivated workspace is handled
 * in code, not by the constraint.
 *
 * Written here (after 2026_08_05_090100_create_workspaces_table) so the
 * referenced table exists. Regenerate the test DB snapshot after pulling this
 * — see the top-level CLAUDE.md, "Unit testing".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('default_workspace_id')
                ->nullable()
                ->after('provider_id')
                ->constrained('workspaces')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_workspace_id');
        });
    }
};
