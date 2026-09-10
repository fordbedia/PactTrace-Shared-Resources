<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * What {@see \PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\ChangeSubscriptionPlan}
 * did with a plan-change request that passed the
 * {@see \PactTrackSDK\SharedResources\Modules\User\Domain\Services\PlanChangePolicy}
 * pre-flight:
 *
 * - `immediate` — the price was swapped now; Stripe bills/credits the
 *   prorated difference on the next invoice.
 * - `noop` — the target tier equalled the current one; nothing was sent to
 *   Stripe.
 *
 * Serialised onto the `POST /billing/change-plan` response so the billing
 * page's success toast can word itself.
 */
final class PlanChangeOutcome
{
    public const IMMEDIATE = 'immediate';

    public const NOOP = 'noop';

    private function __construct(
        public readonly string $status,
        public readonly Plan $targetPlan,
    ) {
    }

    public static function immediate(Plan $targetPlan): self
    {
        return new self(self::IMMEDIATE, $targetPlan);
    }

    public static function noChange(Plan $targetPlan): self
    {
        return new self(self::NOOP, $targetPlan);
    }

    /**
     * @return array{status: string, target_plan: string, target_plan_label: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'target_plan' => $this->targetPlan->value,
            'target_plan_label' => $this->targetPlan->label(),
        ];
    }
}
