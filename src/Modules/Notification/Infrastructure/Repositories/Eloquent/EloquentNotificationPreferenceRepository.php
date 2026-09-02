<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Infrastructure\Repositories\Eloquent;

use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\NotificationPreferenceRepository;
use PactTrackSDK\SharedResources\Modules\Notification\Infrastructure\Repositories\BaseRepository;
use PactTrackSDK\SharedResources\Modules\Notification\Models\NotificationType;
use PactTrackSDK\SharedResources\Modules\Notification\Models\UserNotification;

class EloquentNotificationPreferenceRepository extends BaseRepository implements NotificationPreferenceRepository
{
    public function makeModel(): string
    {
        return NotificationType::class;
    }

    public function allTypes(): Collection
    {
        return NotificationType::query()->ordered()->get();
    }

    public function findType(string $key): ?NotificationType
    {
        return NotificationType::query()->where('key', $key)->first();
    }

	public function findIdByKey(string $key): ?int
    {
        return NotificationType::query()->where('key', $key)->value('id');
    }

    public function overridesForUser(int $userId): Collection
    {
        return UserNotification::query()
            ->where('user_id', $userId)
            ->get()
            ->keyBy('notification_type_id');
    }

    public function upsertOverride(int $userId, int $notificationTypeId, array $channels): UserNotification
    {
        // A user is meant to carry one row per catalogue type at all times
        // (seeded at registration by DefaultNotificationSettings). Materialise
        // any that are missing BEFORE the write so toggling one key can never
        // leave the user with fewer rows than the catalogue — this also
        // self-heals accounts created before that seeding existed, or left
        // partial by the old delete-on-reset behaviour.
        $this->ensureFullSetForUser($userId);

        return UserNotification::query()->updateOrCreate(
            ['user_id' => $userId, 'notification_type_id' => $notificationTypeId],
            array_intersect_key($channels, array_flip(['email', 'in_app', 'sms'])),
        );
    }

    /**
     * "Reset to Defaults" — restores every one of the user's rows to the
     * catalogue defaults. It does NOT delete rows: with a full per-user set
     * seeded at registration, the rows ARE the store, so reset rewrites their
     * values in place (and backfills any that were missing).
     */
    public function deleteAllForUser(int $userId): void
    {
        $this->ensureFullSetForUser($userId);

        foreach (NotificationType::query()->get() as $type) {
            UserNotification::query()
                ->where('user_id', $userId)
                ->where('notification_type_id', $type->getKey())
                ->update([
                    'email' => $type->defaultFor('email'),
                    'in_app' => $type->defaultFor('in_app'),
                    'sms' => $type->defaultFor('sms'),
                ]);
        }
    }

    /**
     * Insert any `user_notifications` rows this user is missing, one per
     * catalogue type, valued from that type's `default_*`. Idempotent and
     * cheap (2 queries + one bulk insert); a no-op once the set is complete.
     */
    private function ensureFullSetForUser(int $userId): void
    {
        $existingTypeIds = UserNotification::query()
            ->where('user_id', $userId)
            ->pluck('notification_type_id')
            ->all();

        $missing = NotificationType::query()
            ->when($existingTypeIds !== [], fn ($q) => $q->whereNotIn('id', $existingTypeIds))
            ->get();

        if ($missing->isEmpty()) {
            return;
        }

        $now = now();

        UserNotification::query()->insert(
            $missing->map(fn (NotificationType $type): array => [
                'user_id' => $userId,
                'notification_type_id' => $type->getKey(),
                'email' => $type->defaultFor('email'),
                'in_app' => $type->defaultFor('in_app'),
                'sms' => $type->defaultFor('sms'),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
        );
    }

	public function createForUser(array $data)
	{
		UserNotification::query()->create($data);
	}
}
