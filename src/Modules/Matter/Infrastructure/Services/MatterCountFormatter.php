<?php

namespace PactTraceSDK\SharedResources\Modules\Matter\Infrastructure\Services;

/**
 * Abbreviates a raw stat-card count (Total Matters/Active/On Hold/Completed,
 * see MatterStatsService) into a compact display string — 1,234 -> "1.2k",
 * 7,800,000 -> "7.8M", and so on up through billions/trillions/"zillions".
 *
 * No port/interface: this is a pure, deterministic formatting rule with
 * nothing to swap, consumed directly from MattersController::stats() the
 * same way MatterProgressCalculator is consumed directly from MatterResource
 * — an Infrastructure class used at a presentation boundary, not through the
 * Application layer.
 */
class MatterCountFormatter
{
    private const UNITS = [
        1_000_000_000_000_000 => 'Z',
        1_000_000_000_000 => 'T',
        1_000_000_000 => 'B',
        1_000_000 => 'M',
        1_000 => 'k',
    ];

    public function format(int $count): string
    {
        $sign = $count < 0 ? '-' : '';
        $magnitude = abs($count);

        foreach (self::UNITS as $threshold => $suffix) {
            if ($magnitude >= $threshold) {
                $value = rtrim(rtrim(number_format($magnitude / $threshold, 1, '.', ''), '0'), '.');

                return $sign . $value . $suffix;
            }
        }

        return $sign . (string) $magnitude;
    }
}
