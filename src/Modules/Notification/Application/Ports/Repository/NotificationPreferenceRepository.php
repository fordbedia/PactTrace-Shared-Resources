<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository;

use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Notification\Models\NotificationType;
use PactTrackSDK\SharedResources\Modules\Notification\Models\UserNotification;

/**
 * Persistence seam for the notification-preferences feature: the type
 * catalogue and one user's overrides. Reads back the `Notification::isset()`
 * helper (via `NotificationPreferenceResolver`) and the `/notification`
 * screen both depend on, and the two writes that screen makes.
 */
interface NotificationPreferenceRepository
{
    /**
     * The whole catalogue, in screen order.
     *
     * @return Collection<int, NotificationType>
     */
    public function allTypes(): Collection;

    /** One type by its machine key, or null if the key is unknown. */
    public function findType(string $key): ?NotificationType;

    /**
     * A user's override rows, keyed by `notification_type_id`.
     *
     * @return Collection<int, UserNotification>
     */
    public function overridesForUser(int $userId): Collection;

    /**
     * Create or update one user's override row for one type.
     *
     * @param  array{email?: bool, in_app?: bool, sms?: bool}  $channels
     */
    public function upsertOverride(int $userId, int $notificationTypeId, array $channels): UserNotification;

    /** Drop every override row for a user — "Reset to Defaults". */
    public function deleteAllForUser(int $userId): void;
}
