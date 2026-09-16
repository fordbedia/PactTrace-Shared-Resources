<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the archive/restore distinction to `matters`, mirroring the Document
 * module's own `archived_at` column — see .claude/rules/matter.md, "Matter
 * Archive / Restore", and .claude/rules/document.md, "Document Deletion &
 * Archival Rules" for the precedent this follows.
 *
 * A separate column rather than overloading `status`: archiving hides a
 * matter from the default dashboard/list views without asserting anything
 * about the underlying engagement's own status (active/on_hold/completed/
 * cancelled) — a completed matter can be archived to tidy the list, and an
 * active matter can be archived too (e.g. put on ice without being
 * "on_hold"). There is no soft-delete concept on `matters` at all — this is
 * purely a visibility flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
