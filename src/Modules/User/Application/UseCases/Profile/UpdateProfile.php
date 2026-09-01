<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Profile;

use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The "Save Changes" action on the `/profile` identity card.
 *
 * The card shows First Name / Last Name separately but `users.name` is a
 * single column — they're recombined here (and split back apart in the UI).
 * `title` is not editable ("Managed by firm owner" in the design), so it's
 * not a parameter.
 *
 * Changing the email address clears `email_verified_at` — the new address is
 * unverified by definition. There's no re-verification flow wired yet, so the
 * "Verified" pill simply drops until one exists.
 */
final class UpdateProfile
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function handle(
        User $actor,
        string $firstName,
        string $lastName,
        string $email,
        ?string $phone,
    ): User {
        $attributes = [
            'name' => trim($firstName . ' ' . $lastName),
            // Same normalisation rule as UserRegistration::normalizeEmail() —
            // the column is compared with `=` under a UNIQUE index.
            'email' => Str::lower(trim($email)),
            'phone' => $phone !== null && trim($phone) !== '' ? trim($phone) : null,
        ];

        $changed = [];
        foreach ($attributes as $key => $value) {
            if ((string) $actor->getAttribute($key) !== (string) $value) {
                $changed[] = $key;
            }
        }

        if (in_array('email', $changed, true)) {
            $attributes['email_verified_at'] = null;
        }

        $user = $this->users->saveAttributes($actor, $attributes);

        if ($changed !== []) {
            AuditLog::create([
                'provider_id' => $user->provider_id,
                'user_id' => $user->id,
                'action' => 'profile.updated',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'metadata' => ['changed' => $changed],
            ]);
        }

        return $user;
    }
}
