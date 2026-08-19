<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the send/sign lifecycle status and the archive/delete distinction to
 * `documents` — see .claude/rules/document.md, "Document Deletion &
 * Archival Rules".
 *
 * - `status` drives DocumentDeletionPolicy / VoidDocumentHandler; every
 *   existing row is a plain upload, so it defaults to `draft`.
 * - `archived_at` is deliberately a separate column from `deleted_at`,
 *   not a reuse of it — archiving hides a document from default dashboard
 *   views without touching the audit trail; deletion (only ever allowed
 *   from `draft`) is the destructive action.
 * - `deleted_at` (SoftDeletes) did not exist on this table before now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('status')->default('draft')->after('version');
            $table->timestamp('archived_at')->nullable()->after('status');
            $table->softDeletes()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['status', 'archived_at']);
        });
    }
};
