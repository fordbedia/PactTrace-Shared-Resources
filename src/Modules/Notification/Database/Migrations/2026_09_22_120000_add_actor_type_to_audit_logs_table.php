<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the audit trail's actor explicit: today `user_id === null` is
 * rendered identically for a system-initiated row (a trial expiring) and
 * for a guest signer's own action, since a guest signer has no `users` row
 * to attach. `actor_type` (`user` | `guest_signer` | `system`) disambiguates
 * that — see .claude/rules/notification.md and the audit-log guest/client
 * activity build. It is intentionally NOT a new FK: a guest signer's
 * identity (name/email) is captured in `metadata` at write time instead —
 * see AuditLogResource::actorName()/actorEmail().
 *
 * Backfill covers every EXISTING row (`user` when `user_id` is set, else
 * `system`) — inspection at build time found the existing `envelope.*`
 * status-transition rows carry no per-signer metadata (no
 * `metadata.signer_name`/`signer_email`), so none of them can be reliably
 * reclassified to `guest_signer` after the fact. Only NEW per-signer rows
 * (written going forward by RecordSignatureCompletionUseCase) ever set
 * `actor_type = 'guest_signer'`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor_type')->nullable()->after('user_id');
        });

        DB::table('audit_logs')->whereNotNull('user_id')->update(['actor_type' => 'user']);
        DB::table('audit_logs')->whereNull('user_id')->update(['actor_type' => 'system']);
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('actor_type');
        });
    }
};
