<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `matter_type` — a real, per-matter classification (Agreement/Letter/
 * Contract/Other; see Domain/Enums/MatterType.php) — replacing what was
 * previously a decorative, unwired "Matter Type" select on the Upload
 * Documents modal (/dashboard/documents). See .claude/rules/matter.md,
 * "Matter Type and Edit Matter" for why this belongs on the Matter itself
 * rather than on each document uploaded against it: a type describes the
 * ongoing engagement, not a single upload event.
 *
 * Nullable: every existing matter predates this column, and picking a type
 * is optional going forward (mirrors `description`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->string('matter_type')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->dropColumn('matter_type');
        });
    }
};
