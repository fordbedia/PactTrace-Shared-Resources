<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Ports;

/**
 * Outbound port to wherever a user's profile-photo bytes live. Bound to a
 * public-disk adapter in UserProvider; per the hexagonal rule in CLAUDE.md
 * nothing outside Infrastructure/ should touch Laravel's Storage facade
 * directly.
 *
 * Shaped like Document's `DocumentStorage` port, with one deliberate
 * difference: avatars are served straight from a public URL, so this port
 * exposes `url()` and has no `get()` — documents are private and streamed
 * through an authorised endpoint instead.
 *
 * `path` is the value stored in `users.avatar_path` — a storage key with no
 * meaning to the domain beyond "what this adapter understands".
 */
interface AvatarStorage
{
    public function put(string $path, string $contents): void;

    /** Remove the file at $path. A no-op when it is already gone. */
    public function delete(string $path): void;

    /** A publicly reachable URL for $path. */
    public function url(string $path): string;
}
