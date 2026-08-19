<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Port for persisting login identities.
 *
 * Implemented by Infrastructure\Repositories\Eloquent\EloquentUserRepository.
 * Application code depends on this interface so the use cases can be tested
 * against a fake, and so spatie's role API never leaks past the adapter —
 * `assignRole()` below is the only way the domain talks about roles.
 *
 * Note the Eloquent `User` in the signatures: strictly, a port should speak in
 * domain entities, but this codebase treats the module's models as the shared
 * currency between layers (UploadDocument returns a Document, etc.). Keeping
 * that consistent matters more here than purity; swap to a domain entity only
 * when every module does.
 */
interface UserRepository
{
    public function create(array $data): User;

    public function findByEmail(string $email): ?User;

    public function emailExists(string $email): bool;

    /**
     * Back-fill the tenant on a user created before its Provider row existed.
     *
     * Named explicitly rather than a generic `update()` because BaseRepository
     * already defines `update(array $data, ?int $id)` with an incompatible
     * signature.
     */
    public function assignProvider(User $user, int $providerId): User;

    public function assignRole(User $user, Role $role): User;
}
