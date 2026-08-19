<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `provider` (which e-signature provider this row is with — 'documenso'
 * today, kept as a plain string rather than an enum so a future adapter
 * doesn't need a migration) and widens `status` to the full envelope
 * lifecycle needed for webhook-driven completion tracking: adds
 * `partially_signed`/`completed`/`expired`, drops the ambiguous `signed`
 * (superseded by `partially_signed` vs `completed` — mirrors
 * documents.status, see .claude/rules/document.md). Raw SQL rather than
 * Blueprint::change() because this package has no doctrine/dbal dependency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function ($table) {
            $table->string('provider')->default('documenso')->after('client_id');
        });

        DB::statement(
            "ALTER TABLE envelopes MODIFY status "
            . "ENUM('draft','sent','viewed','partially_signed','completed','declined','voided','expired') "
            . "NOT NULL DEFAULT 'draft'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE envelopes MODIFY status "
            . "ENUM('draft','sent','viewed','signed','declined','voided') "
            . "NOT NULL DEFAULT 'draft'"
        );

        Schema::table('envelopes', function ($table) {
            $table->dropColumn('provider');
        });
    }
};
