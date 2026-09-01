<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Profile;

use Illuminate\Contracts\Hashing\Hasher;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\InvalidCurrentPasswordException;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The "Update Password" action on the `/profile` password card.
 *
 * The FormRequest has already checked the new password against the card's
 * strength rules (>= 8, upper, number, symbol) and that the confirmation
 * matches. This use case owns the one thing validation can't: proving the
 * caller knows the *current* password before it's replaced.
 *
 * The new value is passed as plain text — the User model's `password =>
 * 'hashed'` cast hashes it on write; hashing here would double-hash and lock
 * the user out (see UserRegistration::register()).
 */
final class ChangeOwnPassword
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Hasher $hasher,
    ) {
    }

    /**
     * @throws InvalidCurrentPasswordException
     */
    public function handle(User $actor, string $currentPassword, string $newPassword): void
    {
        if (! $this->hasher->check($currentPassword, (string) $actor->password)) {
            throw new InvalidCurrentPasswordException();
        }

        $this->users->saveAttributes($actor, ['password' => $newPassword]);

        AuditLog::create([
            'provider_id' => $actor->provider_id,
            'user_id' => $actor->id,
            'action' => 'profile.password_changed',
            'auditable_type' => User::class,
            'auditable_id' => $actor->id,
        ]);
    }
}
