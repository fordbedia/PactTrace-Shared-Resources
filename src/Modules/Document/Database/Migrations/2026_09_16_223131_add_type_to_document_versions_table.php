<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            // Which kind of prior content this archived row holds — see
            // Domain/Enums/DocumentVersionType and .claude/rules/document.md,
            // "Signed document storage". Default 'original' is safe: the
            // table has never had a writer before this feature, so there are
            // no pre-existing rows to backfill differently.
            $table->string('type')->default('original')->after('version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
