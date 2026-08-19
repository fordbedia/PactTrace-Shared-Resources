<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Documenso has been removed in favor of DocuSign (see .claude/rules/signature.md)
 * — new envelopes should default to `provider = 'docusign'`, and any rows
 * created during the Documenso prototype get relabeled to match (local/dev
 * data only; PactTrack never went to production on Documenso). Raw SQL, same
 * reason as the migration this follows: no doctrine/dbal dependency.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE envelopes MODIFY provider VARCHAR(255) NOT NULL DEFAULT 'docusign'");
        DB::table('envelopes')->where('provider', 'documenso')->update(['provider' => 'docusign']);
    }

    public function down(): void
    {
        DB::table('envelopes')->where('provider', 'docusign')->update(['provider' => 'documenso']);
        DB::statement("ALTER TABLE envelopes MODIFY provider VARCHAR(255) NOT NULL DEFAULT 'documenso'");
    }
};
