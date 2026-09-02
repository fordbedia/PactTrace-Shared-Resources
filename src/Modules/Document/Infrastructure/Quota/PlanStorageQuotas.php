<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Quota;

use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\StorageQuotas;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;

/**
 * Resolves a plan's storage allowance straight from the `Plan` enum — the one
 * source of truth for both "what plans exist" and "what each allows".
 *
 * Replaced `ConfigStorageQuotas`, which read a hand-maintained
 * `config('document.storage_quota_bytes')` array that had already drifted from
 * the plan list. The `StorageQuotas` port is unchanged — a future per-tenant
 * override (a column, a Stripe metered entitlement) still plugs in behind it
 * without touching the calculator that consumes it.
 *
 * Fails soft, exactly as the port requires: an unknown, empty or null plan
 * string falls back to `Plan::default()` (the smallest tier) rather than
 * throwing or handing out a larger allowance than the tenant paid for.
 */
final class PlanStorageQuotas implements StorageQuotas
{
    public function bytesForPlan(?string $plan): int
    {
        return (Plan::tryFrom($plan ?? '') ?? Plan::default())->storageLimitBytes();
    }
}
