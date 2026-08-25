<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `batch_id` groups every Envelope created by one "Prepare All for
 * Signature" click on the Matter Detail page (see .claude/rules/matter.md)
 * — null for the existing single-document "Prepare for Signature" path,
 * which is unaffected. It exists solely so
 * RecordSignatureCompletionUseCase::notifyClient() can collapse N
 * webhook-driven `draft -> sent` transitions sharing one batch into a
 * single client email instead of the per-envelope email every other
 * envelope in this codebase sends — see .claude/rules/signature.md,
 * "Notification dispatch is per-envelope, confirmed, never batched" for why
 * this is a deliberate, narrowly-scoped exception rather than a reversal of
 * that rule. Not unique — many rows share one batch_id — hence a plain
 * index rather than the `ulid()` column's usual uniqueness.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->ulid('batch_id')->nullable()->after('provider_envelope_id');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->dropColumn('batch_id');
        });
    }
};
