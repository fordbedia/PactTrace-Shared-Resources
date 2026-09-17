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
        Schema::table('envelopes', function (Blueprint $table) {
            // Idempotency marker for StoreSignedDocumentCopy — set once the
            // signed document has actually been fetched from DocuSign and
            // written onto the Document. Nullable/unset means "not fetched
            // yet" (or predates this feature). See
            // .claude/rules/signature.md, "Fetching the signed document
            // after completion".
            $table->timestamp('signed_document_stored_at')->nullable()->after('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->dropColumn('signed_document_stored_at');
        });
    }
};
