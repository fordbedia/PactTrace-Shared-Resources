<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Infrastructure\Upload;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;

/**
 * Puts a message attachment's bytes into object storage and returns the
 * key (`message_attachments.s3_path`).
 *
 * Deliberately depends on the Document module's DocumentStorage **port**,
 * not on its DocumentUploadService — reusing the proven S3/local adapter
 * (bound in DocumentProvider) without inheriting document-specific key
 * layout or semantics. Same Infrastructure-layer responsibility split as
 * DocumentUploadService itself: "how a storage key is built" is an
 * adapter concern; SendMessageAction only needs the resulting path back.
 *
 * Attachments live under `message-attachments/{providerId}/…` so they are
 * trivially distinguishable from `documents/{providerId}/…` in the same
 * bucket.
 */
class MessageAttachmentStorageService
{
    public function __construct(
        private readonly DocumentStorage $storage,
    ) {
    }

    public function store(UploadedFile $file, int $providerId): string
    {
        $path = sprintf(
            'message-attachments/%d/%s-%s',
            $providerId,
            (string) Str::uuid(),
            $file->getClientOriginalName(),
        );

        $this->storage->put($path, (string) file_get_contents($file->getRealPath()));

        return $path;
    }
}
