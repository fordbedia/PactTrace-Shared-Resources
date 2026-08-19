<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Domain\Ports;

/**
 * Outbound port to wherever document bytes actually live. Bound to an S3
 * adapter in DocumentProvider today; per the hexagonal rule in the
 * top-level CLAUDE.md, nothing outside Infrastructure/ should talk to the
 * AWS SDK (or Laravel's Storage facade) directly — go through this port.
 *
 * `path` is always the value that ends up in Document::s3_path /
 * DocumentVersion::s3_path — a string column with no meaning to the domain
 * beyond "the storage key this adapter understands."
 */
interface DocumentStorage
{
    public function put(string $path, string $contents): void;

    public function delete(string $path): void;

    public function exists(string $path): bool;

    public function get(string $path): string;
}
