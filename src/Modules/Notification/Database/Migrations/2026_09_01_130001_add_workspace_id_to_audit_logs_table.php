<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalises `workspace_id` onto `audit_logs` so the audit trail — the
 * /dashboard/audit-log screen and the dashboard's "Recent Activity" card, both
 * of which read EloquentAuditLogRepository — is scoped to one workspace the
 * same way matters/documents/envelopes already are.
 *
 * `nullOnDelete` rather than `cascadeOnDelete` (the shape the other scoped
 * tables use): an audit row recording that a workspace was deactivated must
 * not itself vanish if that workspace is later hard-deleted — it just stops
 * being attributable to a specific workspace.
 *
 * AuditLog::workspaceIdFromParent() inherits the value from the row's
 * `auditable` when that record carries a workspace; account/team/billing
 * events fall through to BelongsToWorkspace's current-context fallback, so new
 * rows are essentially never null in practice.
 *
 * One-time backfill below: every audit row written before this migration has
 * `workspace_id = null`, and since SQL `=` never matches NULL, that history
 * would drop out of any workspace-scoped view. The backfill copies the
 * workspace from each row's auditable where one is resolvable; rows whose
 * auditable isn't workspace-scoped (subscriptions, users, team invitations)
 * stay null. It is one-way — down() only drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('workspace_id')
                ->nullable()
                ->after('provider_id')
                ->constrained('workspaces')
                ->nullOnDelete();
        });

        // Discover the auditable types actually present rather than hardcoding
        // a class list: for each one that carries its own workspace_id, copy it
        // onto the audit row.
        $types = DB::table('audit_logs')
            ->whereNotNull('auditable_type')
            ->distinct()
            ->pluck('auditable_type');

        foreach ($types as $type) {
            if (! is_string($type) || ! class_exists($type)) {
                continue;
            }

            $instance = new $type();

            // The marker for a workspace-scoped model — defined on the
            // BelongsToWorkspace trait, absent everywhere else.
            if (! method_exists($instance, 'workspaceIdFromParent')) {
                continue;
            }

            $auditableTable = $instance->getTable();

            if (! Schema::hasColumn($auditableTable, 'workspace_id')) {
                continue;
            }

            DB::table('audit_logs')
                ->join($auditableTable, function ($join) use ($auditableTable, $type): void {
                    $join->on('audit_logs.auditable_id', '=', $auditableTable . '.id')
                        ->where('audit_logs.auditable_type', '=', $type);
                })
                ->whereNull('audit_logs.workspace_id')
                ->whereNotNull($auditableTable . '.workspace_id')
                ->update([
                    'audit_logs.workspace_id' => DB::raw($auditableTable . '.workspace_id'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};
