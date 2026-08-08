<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Infrastructure\S3;

use Illuminate\Support\Facades\Storage;
use PactTraceSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;

/**
 * Adapter implementing DocumentStorage against a Laravel filesystem disk.
 * Named for the disk it's bound to in production (S3), but takes the disk
 * name as a constructor argument so local dev can point it at the `local`
 * disk without a code change — see DocumentProvider's binding.
 */
class S3DocumentStorage implements DocumentStorage
{
    public function __construct(
        private readonly string $disk,
    ) {
    }

    public function put(string $path, string $contents): void
    {
        Storage::disk($this->disk)->put($path, $contents);
    }

    public function delete(string $path): void
    {
        Storage::disk($this->disk)->delete($path);
    }

    public function exists(string $path): bool
    {
        return Storage::disk($this->disk)->exists($path);
    }

    public function get(string $path): string
    {
        return Storage::disk($this->disk)->get($path);
    }
}
