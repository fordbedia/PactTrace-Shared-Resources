<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * The billing cadence a Stripe Price is sold under. Orthogonal to {@see Plan}
 * (which tier) — together they're the two axes {@see StripePriceCatalog}
 * maps to one Stripe Price id. Framework-free by the hexagonal rule in
 * CLAUDE.md.
 */
enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}
