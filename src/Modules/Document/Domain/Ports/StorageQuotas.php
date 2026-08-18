<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Domain\Ports;

/**
 * Outbound port for "how many bytes does this plan allow". Implemented by
 * Infrastructure/Quota/ConfigStorageQuotas today; the port exists so a future
 * per-tenant override (a column, a Stripe metered entitlement) can replace the
 * config lookup without touching the service that consumes it.
 */
interface StorageQuotas
{
    /**
     * Bytes allowed on a plan. Must fail soft on an unknown or null plan by
     * returning the default allowance — never throw, and never return a
     * larger allowance than the tenant paid for.
     */
    public function bytesForPlan(?string $plan): int;
}
