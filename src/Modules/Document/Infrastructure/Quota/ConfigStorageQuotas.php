<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Quota;

use Illuminate\Contracts\Config\Repository as Config;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\StorageQuotas;

/**
 * Reads plan allowances from `config('document.storage_quota_bytes')` — the
 * only class in the module that knows the quota is a config file at all
 * (same shape as the Workspace module's ConfigWorkspacePresets).
 *
 * Resolves defensively, because config is editable by hand and a broken entry
 * must not produce a divide-by-zero or a wildly wrong progress bar. Each
 * lookup falls back plan -> configured default -> hardcoded floor.
 */
final class ConfigStorageQuotas implements StorageQuotas
{
    /** Last-resort allowance (10 GB), used only when config is missing or broken. */
    private const FLOOR_BYTES = 10 * 1024 * 1024 * 1024;

    public function __construct(private readonly Config $config)
    {
    }

    public function bytesForPlan(?string $plan): int
    {
        $default = $this->positiveInt($this->config->get('document.storage_quota_bytes.default')) ?? self::FLOOR_BYTES;

        if ($plan === null || $plan === '' || $plan === 'default') {
            return $default;
        }

        return $this->positiveInt($this->config->get("document.storage_quota_bytes.{$plan}")) ?? $default;
    }

    /**
     * Normalises a config entry to a usable byte count, or null when it can't
     * be one — a string, an array, zero or a negative number all mean "this
     * entry is not a quota", and should fall through to the next fallback
     * rather than reaching StorageUsage.
     */
    private function positiveInt(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        return (int) $value > 0 ? (int) $value : null;
    }
}
