<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\BillingInterval;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;

/**
 * POST /billing/checkout — validated by the closed-set enums rather than a
 * hand-maintained `in:` list, so a new Plan/BillingInterval case is picked up
 * here automatically.
 */
class CheckoutRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', 'in:' . implode(',', Plan::values())],
            'billing_interval' => ['sometimes', 'string', 'in:' . implode(',', array_map(
                static fn (BillingInterval $interval): string => $interval->value,
                BillingInterval::cases(),
            ))],
        ];
    }
}
