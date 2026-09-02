<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Support;

use PactTrackSDK\SharedResources\Modules\Notification\Domain\Support\NotificationType;

class DefaultNotificationSettings
{
	public static function forUser(int $userId)
	{
		return array_map(fn (NotificationType $item) => [
			'notification_type_id' => Notification::getIdByKey($item->value),
			'email' => $item->defaultEmailSetting(),
			'in_app' => $item->defaultInAppSetting(),
			'sms' => $item->defaultSmsSetting(),
			'user_id' => $userId
		], NotificationType::cases());
	}
}