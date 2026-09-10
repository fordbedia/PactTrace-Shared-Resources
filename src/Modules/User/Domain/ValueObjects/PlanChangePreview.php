<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * A **non-mutating** estimate of what a plan change would cost — what
 * `POST /billing/change-plan/preview` returns so the billing page's
 * confirmation modal can show real figures before the owner commits.
 *
 * Built from Stripe's `invoices/create_preview` with `create_prorations`
 * (the same behaviour {@see ChangeSubscriptionPlan} applies). The change is
 * immediate, so nothing is due today; `nextInvoiceTotalCents` is the
 * upcoming invoice including the proration charge/credit (the figure the
 * Stripe portal shows as "next estimated payment"), and `lineItems` is the
 * proration breakdown.
 *
 * All money is integer minor units (cents), matching Stripe.
 */
final class PlanChangePreview
{
    /**
     * @param  list<array{description: string, amount_cents: int}>  $lineItems
     */
    public function __construct(
        public readonly int $dueTodayCents,
        public readonly int $nextInvoiceTotalCents,
        public readonly ?string $nextInvoiceDateIso,
        public readonly int $recurringAmountCents,
        public readonly string $currency,
        public readonly array $lineItems,
    ) {
    }

    /**
     * @return array{
     *     due_today_cents: int,
     *     next_invoice_total_cents: int,
     *     next_invoice_date: ?string,
     *     recurring_amount_cents: int,
     *     currency: string,
     *     line_items: list<array{description: string, amount_cents: int}>
     * }
     */
    public function toArray(): array
    {
        return [
            'due_today_cents' => $this->dueTodayCents,
            'next_invoice_total_cents' => $this->nextInvoiceTotalCents,
            'next_invoice_date' => $this->nextInvoiceDateIso,
            'recurring_amount_cents' => $this->recurringAmountCents,
            'currency' => $this->currency,
            'line_items' => $this->lineItems,
        ];
    }
}
