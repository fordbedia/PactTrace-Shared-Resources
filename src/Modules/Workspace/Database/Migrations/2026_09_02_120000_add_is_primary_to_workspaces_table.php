<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `workspaces.is_primary` — the one workspace a provider can never deactivate.
 *
 * Every other module implicitly assumes at least one workspace exists (see
 * RequestWorkspaceContext's "sole workspace" fallback, RegisterProvider's
 * comment about the clients-list leak). Nothing guarded that assumption on the
 * write side: a direct `DELETE /workspaces/{id}` could soft-delete every last
 * one. This column marks the protected one.
 *
 * There is deliberately NO app surface that sets or moves this flag — it is
 * written exactly once, by RegisterProvider, when the tenant's first workspace
 * is created. `StoreWorkspaceRequest` / `CreateWorkspace` / `UpdateWorkspace`
 * never accept it, so a partial-unique "one primary per provider" DB
 * constraint (awkward on MySQL) isn't worth adding for a column with a single
 * writer — application-level correctness is enough.
 *
 * The backfill below is a clean one-shot: existing providers pre-date the
 * column, so their earliest surviving workspace is promoted to primary. `down()`
 * only drops the column (the backfill is not reversed — there is nothing to
 * restore it to).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->boolean('is_primary')->default(false)->after('workspace_type');
        });

        // One primary per provider: the oldest still-active workspace. A
        // provider whose every workspace is already deactivated gets none —
        // there is nothing there to protect.
        $primaryIds = DB::table('workspaces')
            ->whereNull('deleted_at')
            ->groupBy('provider_id')
            ->selectRaw('MIN(id) as id')
            ->pluck('id');

        if ($primaryIds->isNotEmpty()) {
            DB::table('workspaces')
                ->whereIn('id', $primaryIds)
                ->update(['is_primary' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('is_primary');
        });
    }
};
