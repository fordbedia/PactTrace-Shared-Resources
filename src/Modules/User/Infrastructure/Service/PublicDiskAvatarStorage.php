<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Service;

use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\AvatarStorage;

/**
 * AvatarStorage against a Laravel filesystem disk — the one configured as
 * `filesystems.avatar_disk` (defaults to the app's existing `public` disk;
 * point AVATAR_STORAGE_DISK at `s3` in production). Takes the disk name as a
 * constructor argument so tests can hand it a faked disk, mirroring
 * Document's `S3DocumentStorage`.
 *
 * Everything it writes is `public` visibility — the URL is handed straight to
 * an <img> tag with no authorised proxy in front of it.
 */
final class PublicDiskAvatarStorage implements AvatarStorage
{
    public function __construct(
        private readonly string $disk,
    ) {
    }

    public function put(string $path, string $contents): void
    {
        Storage::disk($this->disk)->put($path, $contents, 'public');
    }

    public function delete(string $path): void
    {
        Storage::disk($this->disk)->delete($path);
    }

    public function url(string $path): string
    {
        return Storage::disk($this->disk)->url($path);
    }
}
