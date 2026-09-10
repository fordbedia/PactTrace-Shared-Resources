<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cached provider-wide stored-bytes total.
 *
 * "Used storage" was a live `SUM(documents.size)` on every dashboard /
 * document-page / plan-gate read, and it counted only `documents` — never
 * `message_attachments`, so a client attaching files to a message consumed
 * real S3 storage that never hit the plan quota. This column is the fix:
 * maintained at write time by
 * User\Application\Services\ProviderStorageLedger, recomputed nightly from
 * the full StorageSource registry by `storage:reconcile`
 * (User\Application\UseCases\ReconcileProviderStorageUsage), and read by
 * every request path via CachedStorageUsageReader.
 *
 *  - `storage_used_bytes`      unsignedBigInteger, not a plain integer — a
 *                              provider's library overflows a 32-bit int
 *                              (~2 GB) long before any realistic plan cap.
 *  - `storage_recalculated_at` when `storage:reconcile` last verified the
 *                              value for this provider.
 *
 * The same `up()` backfills existing rows (documents + own-bytes message
 * attachments) so no provider reads 0 until the first nightly run — a plain
 * SQL rollup, matching this repo's other backfill migrations, with no
 * dependency on the service container.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->unsignedBigInteger('storage_used_bytes')->default(0)->after('plan');
            $table->timestamp('storage_recalculated_at')->nullable()->after('storage_used_bytes');
        });

        // Backfill: documents (non-soft-deleted, matching DocumentStorageSource)
        // plus message attachments that own their own bytes (`document_id IS
        // NULL` — an attachment pointing at a Document is already counted
        // there). Archived (soft-deleted) message threads are intentionally
        // still counted: their files remain in S3.
        DB::statement(<<<'SQL'
            UPDATE providers p
            SET storage_used_bytes =
                COALESCE((
                    SELECT SUM(d.size)
                    FROM documents d
                    WHERE d.provider_id = p.id
                      AND d.deleted_at IS NULL
                ), 0)
                + COALESCE((
                    SELECT SUM(ma.size)
                    FROM message_attachments ma
                    JOIN messages m ON m.id = ma.message_id
                    JOIN message_threads mt ON mt.id = m.thread_id
                    WHERE mt.provider_id = p.id
                      AND ma.document_id IS NULL
                ), 0),
                storage_recalculated_at = NOW()
        SQL);
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn(['storage_used_bytes', 'storage_recalculated_at']);
        });
    }
};
