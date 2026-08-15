<?php

namespace PactTraceSDK\SharedResources\Modules\User\Domain\Enum;

enum SubscriptionPlan: string
{
	case Starter = 'starter';
	case Professional = 'professional';
	case Firm = 'firm';

	public function getPlan(): string
	{
		return match($this) {
			self::Starter => 'starter',
			self::Firm => 'firm',
			default => 'professional',
		};
	}
}