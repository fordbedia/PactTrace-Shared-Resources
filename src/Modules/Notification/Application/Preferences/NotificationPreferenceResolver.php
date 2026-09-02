<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Application\Preferences;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\NotificationPreferenceRepository;
use PactTrackSDK\SharedResources\Modules\Notification\Models\NotificationType;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Answers "is notification X on for this user, on this channel?" — the engine
 * behind the `Notification::isset('key')` helper and the `/notification`
 * screen's effective values.
 *
 * Registered as a singleton (like `WorkspaceLabelResolver`) so the catalogue is
 * read once per request and repeated `Notification::isset(...)` calls in the
 * same request are free. Call `flush()` after a write if a later read in the
 * same request must see it.
 *
 * Resolution for one (user, key, channel):
 *   1. unknown key                    -> false (nothing is "on" for a type that doesn't exist)
 *   2. no user (none passed, no auth) -> false (a send needs a recipient; fail safe)
 *   3. channel locked on the type     -> true  (can't be turned off)
 *   4. user has an override row        -> that row's channel boolean
 *   5. otherwise                       -> the type's `default_<channel>`
 *
 * A channel locked on the type (`<channel>_locked`) always reads as `true`
 * regardless of any override — it can't be turned off.
 */
class NotificationPreferenceResolver
{
    private const CHANNELS = ['email', 'in_app', 'sms'];

    /** @var array<string, NotificationType|null> keyed by type key */
    private array $typeCache = [];

    /** @var array<int, array<int, array{email: bool, in_app: bool, sms: bool}>> keyed by userId, then typeId */
    private array $overrideCache = [];

    public function __construct(
        private readonly NotificationPreferenceRepository $repository,
        private readonly AuthFactory $auth,
    ) {
    }

    /**
     * The single entry point the helper and controller use.
     *
     * @param  'email'|'in_app'|'sms'  $channel
     */
    public function isEnabled(string $key, ?User $user = null, string $channel = 'email'): bool
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            return false;
        }

        $type = $this->resolveType($key);

        if ($type === null) {
            return false;
        }

        if ($type->isLocked($channel)) {
            return true;
        }

        $user ??= $this->currentUser();

        // A notification is always about a specific recipient. With none in
        // hand we can't say it's "on" for anyone — fail safe.
        if ($user === null) {
            return false;
        }

        $override = $this->overridesFor((int) $user->getKey())[$type->getKey()] ?? null;

        if ($override === null) {
            return $type->defaultFor($channel);
        }

        return $override[$channel];
    }

    /**
     * The whole catalogue with one user's effective values folded in — the
     * shape `/notification` renders. Each entry:
     *   key, label, description, group, position,
     *   email, in_app, sms                 (effective booleans)
     *   email_locked, in_app_locked, sms_locked
     *
     * @return list<array<string, mixed>>
     */
    public function catalogueForUser(?User $user = null): array
    {
        $user ??= $this->currentUser();
        $overrides = $user !== null ? $this->overridesFor((int) $user->getKey()) : [];

        return $this->repository->allTypes()->map(function (NotificationType $type) use ($overrides): array {
            $row = [
                'key' => $type->key,
                'label' => $type->label,
                'description' => $type->description,
                'group' => $type->group,
                'position' => $type->position,
            ];

            $override = $overrides[$type->getKey()] ?? null;

            foreach (self::CHANNELS as $channel) {
                $row[$channel] = $type->isLocked($channel)
                    ? true
                    : ($override[$channel] ?? $type->defaultFor($channel));
                $row["{$channel}_locked"] = $type->isLocked($channel);
            }

            return $row;
        })->all();
    }

    /** Forget the per-request caches (call after a write within the same request). */
    public function flush(): void
    {
        $this->typeCache = [];
        $this->overrideCache = [];
    }

    private function resolveType(string $key): ?NotificationType
    {
        if (! array_key_exists($key, $this->typeCache)) {
            $this->typeCache[$key] = $this->repository->findType($key);
        }

        return $this->typeCache[$key];
    }

    /**
     * @return array<int, array{email: bool, in_app: bool, sms: bool}> keyed by notification_type_id
     */
    private function overridesFor(int $userId): array
    {
        if (! array_key_exists($userId, $this->overrideCache)) {
            $this->overrideCache[$userId] = $this->repository->overridesForUser($userId)
                ->map(static fn ($row): array => [
                    'email' => (bool) $row->email,
                    'in_app' => (bool) $row->in_app,
                    'sms' => (bool) $row->sms,
                ])
                ->all();
        }

        return $this->overrideCache[$userId];
    }

    private function currentUser(): ?User
    {
        $user = $this->auth->guard()->user();

        return $user instanceof User ? $user : null;
    }
}
