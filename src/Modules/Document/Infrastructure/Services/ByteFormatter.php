<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Services;

/**
 * Formats a byte count for display — 6,657,199,308 -> "6.2 GB", 512,000 ->
 * "500 KB", 0 -> "0 B". Backs the STORAGE indicator's "X of Y used" copy on
 * /dashboard/documents.
 *
 * Binary units (1 KB = 1024 B), matching how the quotas in
 * config/document.php are written and how a file manager reports the same
 * file. A trailing ".0" is trimmed so a round number reads "10 GB", not
 * "10.0 GB" — the artboard's wording.
 *
 * No port/interface: a pure, deterministic formatting rule with nothing to
 * swap, consumed at the presentation boundary the same way the Matter
 * module's MatterCountFormatter is.
 */
class ByteFormatter
{
    private const UNITS = [
        1024 ** 4 => 'TB',
        1024 ** 3 => 'GB',
        1024 ** 2 => 'MB',
        1024 => 'KB',
    ];

    public function format(int $bytes): string
    {
        $sign = $bytes < 0 ? '-' : '';
        $magnitude = abs($bytes);

        foreach (self::UNITS as $threshold => $suffix) {
            if ($magnitude >= $threshold) {
                $value = rtrim(rtrim(number_format($magnitude / $threshold, 1, '.', ''), '0'), '.');

                return $sign . $value . ' ' . $suffix;
            }
        }

        return $sign . $magnitude . ' B';
    }
}
