<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Action;

use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\MessageAttachmentDownload;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageAttachment;
use RuntimeException;

/**
 * Reads one message attachment's bytes back out of storage — backs
 * `GET /api/v1/messages/attachments/{attachment}` (staff) and
 * `GET /api/v1/portal/message-attachments/{attachment}` (portal). The
 * controller has already resolved the attachment and authorised
 * MessageThreadPolicy::view on its thread, so a client can never pull an
 * attachment from a thread that isn't theirs — the same view scoping the
 * conversation itself is behind.
 *
 * Depends on the Document module's DocumentStorage port (the same S3/local
 * adapter message uploads are written through — see
 * MessageAttachmentStorageService), never Laravel's Storage facade
 * directly, per the hexagonal rule in the top-level CLAUDE.md.
 */
class DownloadMessageAttachment
{
    public function __construct(
        private readonly DocumentStorage $storage,
    ) {
    }

    public function handle(MessageAttachment $attachment): MessageAttachmentDownload
    {
        // Every attachment created today carries its own `s3_path`
        // (AppendMessageToThread always stores the bytes). A
        // `document_id`-only attachment has no standalone object to serve —
        // that path isn't produced anywhere yet.
        if (empty($attachment->s3_path)) {
            throw new RuntimeException('This attachment has no downloadable file.');
        }

        return new MessageAttachmentDownload(
            fileName: (string) $attachment->file_name,
            mimeType: $attachment->mime_type ?: 'application/octet-stream',
            contents: $this->storage->get($attachment->s3_path),
        );
    }
}
