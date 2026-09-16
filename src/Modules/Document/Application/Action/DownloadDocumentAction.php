<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Application\Action;

use PactTrackSDK\SharedResources\Modules\Document\Application\DTO\DocumentDownload;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Backs `GET /api/documents/{document}/download` — see
 * .claude/rules/document.md, "Document download". `DocumentPolicy::download`
 * already gates this (no status restriction: a completed/signed document is
 * exactly the case this exists for), so this class only resolves *how* to
 * hand the bytes back and logs the access.
 *
 * Prefers a pre-signed URL (no PactTrack app server in the download's own
 * request path — real for S3 in production) and falls back to streaming
 * `get()`'s bytes when the configured disk can't produce one (the `local`
 * dev disk). Every download — either path — is a distinct, auditable event
 * from merely viewing/listing the document, same reasoning
 * DocumentPolicy::download's own docblock gives.
 */
class DownloadDocumentAction
{
    private const URL_TTL_MINUTES = 5;

    public function __construct(
        private readonly DocumentStorage $storage,
    ) {
    }

    public function handle(Document $document, User $actor): DocumentDownload
    {
        $url = $this->storage->temporaryUrl($document->s3_path, now()->addMinutes(self::URL_TTL_MINUTES));

        AuditLog::create([
            'provider_id' => $document->provider_id,
            'user_id' => $actor->id,
            'action' => 'document.downloaded',
            'auditable_type' => Document::class,
            'auditable_id' => $document->id,
        ]);

        return new DocumentDownload(
            url: $url,
            content: $url === null ? $this->storage->get($document->s3_path) : null,
            fileName: $document->name,
            mimeType: $document->mime_type,
        );
    }
}
