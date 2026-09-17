<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentVersionType;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Document\Models\DocumentVersion;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\ProviderStorageLedger;
use Throwable;

/**
 * Fetches the actual finished/signed document from DocuSign once an envelope
 * reaches `completed`, and makes it the Document's current content — see
 * .claude/rules/signature.md, "Fetching the signed document after
 * completion" and .claude/rules/document.md, "Signed document storage".
 *
 * Dispatched (not run inline) from RecordSignatureCompletionUseCase, on the
 * same `draft`-style guard that already exists there (only on a genuine
 * transition INTO Completed — a redundant/redelivered webhook for an
 * already-completed envelope never reaches that call site at all, since
 * `$envelope->status === $previousStatus` short-circuits first). Queued
 * because this makes a DocuSign HTTP call and a storage write — both slower
 * and less reliable than anything else the webhook request already does,
 * and DocuSign expects a fast 2xx or it will retry the whole delivery. Same
 * `ShouldQueue` / scalars-only-constructor shape as
 * Messaging\Jobs\SendStaffUnreadMessageReminder.
 *
 * Idempotent on its own, independent of the dispatch-site guard above:
 * `envelopes.signed_document_stored_at` is set only once this succeeds, and
 * a job that runs again for the same envelope (a queue retry after a
 * transient failure that actually got far enough to write it, a manual
 * redispatch) is a no-op the moment it sees that column already set.
 *
 * Retries automatically (Laravel's default backoff via `$backoff` below) on
 * a transient DocuSign/storage failure — the envelope/signer status
 * transition this job runs after has already committed by the time this
 * dispatches, so a failure here never blocks or reverts that more
 * time-sensitive, client-facing fact. `$tries` is finite (not infinite) so a
 * permanently-broken case (a deleted DocuSign envelope, a revoked
 * integration) surfaces in the failed_jobs table for a human rather than
 * retrying forever.
 */
class StoreSignedDocumentCopy implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 1800, 3600];

    public function __construct(
        public readonly int $envelopeId,
    ) {
    }

    public function handle(
        ESignatureProvider $eSignatureProvider,
        DocumentStorage $storage,
        DocumentRepository $documents,
        ProviderStorageLedger $storageLedger,
    ): void {
        $envelope = Envelope::query()->find($this->envelopeId);

        if ($envelope === null) {
            Log::warning('StoreSignedDocumentCopy: envelope no longer exists — nothing to store.', [
                'envelope_id' => $this->envelopeId,
            ]);

            return;
        }

        // Idempotency guard — see class docblock. A second run for the same
        // envelope (queue retry after a late failure, manual redispatch)
        // must never fetch/store the signed copy twice.
        if ($envelope->signed_document_stored_at !== null) {
            return;
        }

        $document = $envelope->document()->first();

        if ($document === null) {
            Log::warning('StoreSignedDocumentCopy: envelope has no Document to store a signed copy against.', [
                'envelope_id' => $envelope->id,
            ]);

            return;
        }

        $bytes = $eSignatureProvider->fetchCompletedDocument($envelope->provider_envelope_id);

        $path = sprintf(
            'documents/%d/signed-%s-%s',
            $document->provider_id,
            (string) Str::uuid(),
            $document->name,
        );

        $storage->put($path, $bytes);

        $previousSize = (int) $document->size;

        DB::transaction(function () use ($document, $documents, $envelope, $path, $bytes): void {
            // The content being superseded is 'original' the first time this
            // ever runs for a document (nothing else in this codebase writes
            // a DocumentVersion row — see UploadDocumentAction's own
            // docblock), and 'signed' on every subsequent run (a document
            // re-prepared and re-signed after an earlier envelope was
            // voided — see .claude/rules/signature.md, "Every recipient is a
            // DocuSign Signer"). No extra column needed to track this: it
            // follows directly from whether an archived version already
            // exists.
            $type = $document->versions()->exists() ? DocumentVersionType::Signed : DocumentVersionType::Original;
            $previousVersion = $document->version;

            DocumentVersion::query()->create([
                'document_id' => $document->id,
                'uploaded_by' => $document->uploaded_by,
                's3_path' => $document->s3_path,
                'version' => $previousVersion,
                'size' => $document->size,
                'type' => $type,
            ]);

            $documents->save($document, [
                's3_path' => $path,
                'mime_type' => 'application/pdf',
                'size' => strlen($bytes),
                'version' => $previousVersion + 1,
            ]);

            $envelope->forceFill(['signed_document_stored_at' => now()])->save();

            AuditLog::create([
                'provider_id' => $document->provider_id,
                'user_id' => null,
                'action' => 'document.signed_copy_stored',
                'auditable_type' => Document::class,
                'auditable_id' => $document->id,
                'metadata' => [
                    'envelope_id' => $envelope->id,
                    'previous_version' => $previousVersion,
                    'new_version' => $previousVersion + 1,
                ],
            ]);
        });

        // The archived original stays on disk under document_versions'
        // s3_path but is deliberately excluded from the storage-quota sum
        // (see .claude/rules/document.md, "Storage usage") — so the ledger
        // only needs to track the net change to `documents.size` itself:
        // debit what it used to be, credit what it is now.
        $storageLedger->debit((int) $document->provider_id, $previousSize);
        $storageLedger->credit((int) $document->provider_id, strlen($bytes));
    }

    /**
     * A permanently failed fetch (retries exhausted) still needs to be
     * visible somewhere — logged rather than silently landing only in
     * `failed_jobs`, matching the "a silent failure here is how an envelope
     * sat stuck with nobody noticing" lesson already documented on
     * RecordSignatureCompletionUseCase.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('StoreSignedDocumentCopy: permanently failed to fetch/store the signed document.', [
            'envelope_id' => $this->envelopeId,
            'error' => $exception->getMessage(),
        ]);
    }
}
