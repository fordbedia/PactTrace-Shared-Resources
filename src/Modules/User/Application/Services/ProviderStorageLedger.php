<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Services;

use Illuminate\Support\Facades\DB;

/**
 * Keeps the cached `providers.storage_used_bytes` column current at write
 * time — one credit when a file row is created, one debit when it is deleted.
 *
 * Called from the three Application write points that persist stored bytes
 * (UploadDocumentAction, DeleteDocumentHandler, AppendMessageToThread's
 * attachment loop) rather than through a domain-event bus: this codebase has
 * no event/listener infrastructure, and those three are already
 * Application-layer actions, so a single injected collaborator with one call
 * each is "adjust at the point the row changes" without a new cross-cutting
 * pattern.
 *
 * Each adjustment is a single atomic UPDATE — never a read-modify-write in
 * PHP — so two concurrent uploads on the same provider can't lose one
 * another's delta. The nightly `storage:reconcile`
 * ({@see \PactTrackSDK\SharedResources\Modules\User\Application\UseCases\ReconcileProviderStorageUsage})
 * is the safety net that corrects the column if an adjustment is ever missed.
 */
final class ProviderStorageLedger
{
    public function credit(int $providerId, int $bytes): void
    {
        $this->adjust($providerId, $bytes, '+');
    }

    public function debit(int $providerId, int $bytes): void
    {
        $this->adjust($providerId, $bytes, '-');
    }

    private function adjust(int $providerId, int $bytes, string $op): void
    {
        // A zero/negative delta is a no-op, not an error — an attachment with
        // no recorded size, a document row with size 0.
        if ($bytes <= 0) {
            return;
        }

        // GREATEST(0, …) clamps in the same statement so a debit can never
        // drive the counter negative (a missed earlier credit, a manual DB
        // edit) — and it stays a single atomic write.
        DB::table('providers')
            ->where('id', $providerId)
            ->update([
                'storage_used_bytes' => DB::raw("GREATEST(0, CAST(storage_used_bytes AS SIGNED) {$op} {$bytes})"),
            ]);
    }
}
