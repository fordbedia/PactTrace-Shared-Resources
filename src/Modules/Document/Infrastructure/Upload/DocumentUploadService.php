<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Infrastructure\Upload;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PactTraceSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;

/**
 * Turns an uploaded file into a stored object and the path that points at
 * it. Sits in Infrastructure (not Application) because "how/where a file's
 * storage key is built" is an infrastructure-adapter concern, same as the
 * DocumentStorage port it wraps — UploadDocumentAction only needs the
 * resulting path back, not how it was derived.
 */
class DocumentUploadService
{
    public function __construct(
        private readonly DocumentStorage $storage,
    ) {
    }

    public function store(UploadedFile $file, int $providerId): string
    {
        $path = sprintf(
            'documents/%d/%s-%s',
            $providerId,
            (string) Str::uuid(),
            $file->getClientOriginalName(),
        );

        $this->storage->put($path, (string) file_get_contents($file->getRealPath()));

        return $path;
    }
}
