<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO;

/**
 * The output of DownloadMessageAttachment — a message attachment's bytes
 * plus the metadata an HTTP adapter needs to serve them. The controller
 * turns this into the actual streamed/binary response (an HTTP concern);
 * the Application action only knows "fetch the file".
 */
final readonly class MessageAttachmentDownload
{
    public function __construct(
        public string $fileName,
        public string $mimeType,
        public string $contents,
    ) {
    }
}
