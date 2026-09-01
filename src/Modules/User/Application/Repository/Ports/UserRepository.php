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

    /**
     * Replace whatever role(s) a user holds with exactly `$role` — the
     * "change this teammate's role" path, as opposed to `assignRole()` which
     * is additive and used at first onboarding. Wraps spatie's `syncRoles()`
     * so that API still never leaks past this adapter.
     */
    public function syncRole(User $user, Role $role): User;

    /**
     * Soft-remove a teammate: `status = 'deactivated'`, `deactivated_at = now`.
     *
     * Never a hard delete — `documents.uploaded_by`, `messages.sender_id` and
     * `message_threads.staff_user_id` are all `cascadeOnDelete`, so deleting
     * the row would destroy legal records and the audit trail (and
     * `workspaces.owner_id` is `restrictOnDelete`, which would throw). The
     * `users.status` enum already carries a `deactivated` value for exactly
     * this.
     */
    public function deactivate(User $user): User;
}
