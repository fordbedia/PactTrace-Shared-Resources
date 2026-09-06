<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;

/**
 * POST /billing/change-plan — `target_plan` only. No `billing_interval`
 * field: the endpoint preserves whichever interval the tenant is already on
 * (see Application\UseCases\Billing\ChangeSubscriptionPlan).
 */
class ChangePlanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'target_plan' => ['required', 'string', 'in:' . implode(',', Plan::values())],
        ];
    }
}
