<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanChangeResult;
use RuntimeException;

/**
 * Thrown by `Application\UseCases\Billing\ChangeSubscriptionPlan` when
 * {@see \PactTrackSDK\SharedResources\Modules\User\Domain\Services\PlanChangePolicy}
 * finds the tenant's current usage doesn't fit the target plan.
 * `BillingController::changePlan()` catches this and maps it to a 422
 * carrying `$result->toArray()` verbatim.
 */
class PlanChangeBlockedException extends RuntimeException
{
    public function __construct(
        public readonly PlanChangeResult $result,
    ) {
        parent::__construct('Current usage exceeds the target plan\'s limits.');
    }
}
