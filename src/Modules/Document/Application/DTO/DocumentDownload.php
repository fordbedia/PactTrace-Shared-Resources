<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Application\DTO;

/**
 * The result of DownloadDocumentAction — either a pre-signed `url` the
 * controller redirects to (S3, production), or raw `content` bytes it
 * streams itself (the `local` dev disk, which can't produce a temporary
 * URL). Exactly one of the two is set. See .claude/rules/document.md,
 * "Document download".
 */
final readonly class DocumentDownload
{
    public function __construct(
        public ?string $url,
        public ?string $content,
        public string $fileName,
        public ?string $mimeType,
    ) {
    }

    public function isRedirect(): bool
    {
        return $this->url !== null;
    }
}
