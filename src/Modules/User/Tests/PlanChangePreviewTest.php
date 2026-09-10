<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanChangePreview;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * Pure domain-object test — no DB — for the non-mutating cost estimate the
 * confirmation modal renders. All money is integer cents, matching Stripe.
 * See .claude/rules/plan.md, "Change-plan confirmation modal".
 */
class PlanChangePreviewTest extends BaseTest
{
    private function preview(): PlanChangePreview
    {
        return new PlanChangePreview(
            dueTodayCents: 0,
            nextInvoiceTotalCents: 22900,
            nextInvoiceDateIso: '2026-10-10T00:00:00+00:00',
            recurringAmountCents: 14900,
            currency: 'usd',
            lineItems: [
                ['description' => 'Unused time on Professional', 'amount_cents' => -6880],
                ['description' => 'Remaining time on Firm', 'amount_cents' => 14780],
                ['description' => '1 × Firm - Monthly', 'amount_cents' => 14900],
            ],
        );
    }

    public function test_it_exposes_every_field_as_a_readonly_property(): void
    {
        $p = $this->preview();

        $this->assertSame(0, $p->dueTodayCents);
        $this->assertSame(22900, $p->nextInvoiceTotalCents);
        $this->assertSame('2026-10-10T00:00:00+00:00', $p->nextInvoiceDateIso);
        $this->assertSame(14900, $p->recurringAmountCents);
        $this->assertSame('usd', $p->currency);
        $this->assertCount(3, $p->lineItems);
    }

    public function test_next_invoice_date_is_nullable(): void
    {
        $p = new PlanChangePreview(0, 14900, null, 14900, 'usd', []);

        $this->assertNull($p->nextInvoiceDateIso);
        $this->assertNull($p->toArray()['next_invoice_date']);
    }

    public function test_to_array_is_snake_cased_for_the_wire(): void
    {
        $this->assertSame(
            [
                'due_today_cents' => 0,
                'next_invoice_total_cents' => 22900,
                'next_invoice_date' => '2026-10-10T00:00:00+00:00',
                'recurring_amount_cents' => 14900,
                'currency' => 'usd',
                'line_items' => [
                    ['description' => 'Unused time on Professional', 'amount_cents' => -6880],
                    ['description' => 'Remaining time on Firm', 'amount_cents' => 14780],
                    ['description' => '1 × Firm - Monthly', 'amount_cents' => 14900],
                ],
            ],
            $this->preview()->toArray(),
        );
    }
}
