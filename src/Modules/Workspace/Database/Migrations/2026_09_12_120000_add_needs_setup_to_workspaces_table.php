<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `workspaces.needs_setup` — true only for the placeholder workspace
 * `RegisterProvider` creates during a Google/Microsoft OAuth sign-up, where
 * there is no real practice name to seed it with (the OAuth button collects
 * no form input — see `AuthenticateViaOAuth::deriveBusinessName()`). Every
 * other workspace (password sign-up, `CreateWorkspace`'s "add another
 * workspace" flow) is created with this `false`, via the column default —
 * neither of those call sites ever passes the flag.
 *
 * Cleared back to `false` by `UpdateWorkspace::handle()` on every successful
 * save — including the password sign-up path's onboarding submit, where it
 * was already `false` and this is a harmless no-op write.
 *
 * See `.claude/rules/workspace.md` and `ProtectedRoute` (frontend) for what
 * reads this: a `true` value forces the owner/admin who created it through
 * `/dashboard/create-workspace?onboarding=1` before any other `/dashboard/*`
 * route, with no "Skip for now" escape hatch.
 *
 * Regenerate the test DB snapshot after pulling this — see the top-level
 * `CLAUDE.md`, "Unit testing".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->boolean('needs_setup')->default(false)->after('is_primary');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('needs_setup');
        });
    }
};
