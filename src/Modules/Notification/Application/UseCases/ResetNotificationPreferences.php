<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\NotificationPreferenceRepository;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Preferences\NotificationPreferenceResolver;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * "Reset to Defaults" — drops every override row for the user, so every type
 * falls back to its `default_*`. Backs
 * `POST /api/v1/notification-preferences/reset`.
 */
class ResetNotificationPreferences
{
    public function __construct(
        private readonly NotificationPreferenceRepository $repository,
        private readonly NotificationPreferenceResolver $resolver,
    ) {
    }

    /**
     * @return list<array<string, mixed>>  the full catalogue at defaults
     */
    public function handle(User $user): array
    {
        $this->repository->deleteAllForUser((int) $user->getKey());
        $this->resolver->flush();

        return $this->resolver->catalogueForUser($user);
    }
}
