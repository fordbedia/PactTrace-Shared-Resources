<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Application\UseCases;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\NotificationPreferenceRepository;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Preferences\NotificationPreferenceResolver;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Sets one user's override for one notification type. Backs
 * `PATCH /api/v1/notification-preferences/{key}` — the `/notification`
 * screen's per-toggle auto-save.
 *
 * A locked channel (`<channel>_locked` on the type — e.g. Security alerts on
 * email) is silently ignored rather than rejected: the UI shows it as a
 * "Required" lock with no control, so a request to change it can only come
 * from a stale page or a hand-rolled call, and the safe answer is "it stays
 * on".
 */
class UpdateNotificationPreference
{
    public function __construct(
        private readonly NotificationPreferenceRepository $repository,
        private readonly NotificationPreferenceResolver $resolver,
    ) {
    }

    /**
     * @param  array{email?: bool, in_app?: bool, sms?: bool}  $channels
     * @return array<string, mixed>  the type's effective row for this user, post-write
     *
     * @throws ModelNotFoundException  when `$key` is not a known type
     */
    public function handle(User $user, string $key, array $channels): array
    {
        $type = $this->repository->findType($key);

        if ($type === null) {
            throw (new ModelNotFoundException())->setModel(
                \PactTrackSDK\SharedResources\Modules\Notification\Models\NotificationType::class,
                [$key],
            );
        }

        // Drop any attempt to write a locked channel.
        foreach (['email', 'in_app', 'sms'] as $channel) {
            if ($type->isLocked($channel)) {
                unset($channels[$channel]);
            }
        }

        if ($channels !== []) {
            // Seed the row from the type defaults so an override created by a
            // single-channel PATCH doesn't silently flip the other channels.
            $seed = [
                'email' => $type->defaultFor('email'),
                'in_app' => $type->defaultFor('in_app'),
                'sms' => $type->defaultFor('sms'),
            ];

            $existing = $this->repository->overridesForUser((int) $user->getKey())
                ->get($type->getKey());

            if ($existing !== null) {
                $seed = [
                    'email' => (bool) $existing->email,
                    'in_app' => (bool) $existing->in_app,
                    'sms' => (bool) $existing->sms,
                ];
            }

            $this->repository->upsertOverride(
                (int) $user->getKey(),
                (int) $type->getKey(),
                array_merge($seed, $channels),
            );
        }

        $this->resolver->flush();

        foreach ($this->resolver->catalogueForUser($user) as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        return [];
    }
}
