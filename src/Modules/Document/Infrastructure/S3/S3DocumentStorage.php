<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Infrastructure\S3;

use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;

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

    /**
     * S3 supports pre-signed temporary URLs natively; the `local` driver
     * (dev) does not unless a `temporaryUrlCallback` is configured, which
     * this app doesn't do — Laravel's FilesystemAdapter throws a
     * RuntimeException in that case, caught here so the caller can fall back
     * to streaming instead. See DocumentStorage's own docblock.
     */
    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt): ?string
    {
        try {
            return Storage::disk($this->disk)->temporaryUrl($path, $expiresAt);
        } catch (\Throwable) {
            return null;
        }
    }
}
