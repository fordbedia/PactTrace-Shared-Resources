<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Ports;

/**
 * Outbound port to wherever a provider's portal-logo bytes live. Bound to a
 * public-disk adapter in UserProvider; per the hexagonal rule in CLAUDE.md
 * nothing outside Infrastructure/ should touch Laravel's Storage facade
 * directly.
 *
 * A near-copy of {@see AvatarStorage} — a logo, like an avatar, is served
 * straight from a public URL into an <img>, so this port exposes `url()` and
 * has no `get()`. `diskName()` is the one addition: `providers.disk` records
 * which disk a given row's `logo_path` was written to, so the value can be
 * persisted alongside the path.
 *
 * `path` is the value stored in `providers.logo_path` — a storage key with no
 * meaning to the domain beyond "what this adapter understands".
 */
interface ProviderLogoStorage
{
    public function put(string $path, string $contents): void;

    /** Remove the file at $path. A no-op when it is already gone. */
    public function delete(string $path): void;

    /** A publicly reachable URL for $path. */
    public function url(string $path): string;

    /** The disk name this adapter writes to — persisted to `providers.disk`. */
    public function diskName(): string;
}
