<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\StorageUsageAggregator;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * The drift-correcting safety net behind the nightly `storage:reconcile`
 * scan (see Console\Commands\ReconcileProviderStorage, scheduled in
 * backend/routes/console.php).
 *
 * For every provider it recomputes the true stored-bytes total from the
 * {@see StorageUsageAggregator} (the registry of every StorageSource) and
 * overwrites `providers.storage_used_bytes` when it disagrees with the cached
 * value {@see \PactTrackSDK\SharedResources\Modules\User\Application\Services\ProviderStorageLedger}
 * has been maintaining. `storage_recalculated_at` records the last time each
 * provider was verified.
 *
 * A nonzero delta is logged, not silently patched: bytes are exact integers,
 * so any difference means a real increment/decrement path was missed
 * somewhere and needs finding — same "this is diagnostic evidence, not just a
 * fix" stance as Signature's ReconcileStaleEnvelopes.
 */
final class ReconcileProviderStorageUsage
{
    /** Providers per chunk — a plain id + one column, so this can be generous. */
    private const CHUNK = 200;

    public function __construct(
        private readonly StorageUsageAggregator $aggregator,
    ) {
    }

    /**
     * @return array{checked: int, corrected: int, drift_bytes: int}
     */
    public function handle(): array
    {
        $checked = 0;
        $corrected = 0;
        $driftBytes = 0;
        $now = Carbon::now();

        Provider::query()
            ->select(['id', 'storage_used_bytes'])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($providers) use (&$checked, &$corrected, &$driftBytes, $now): void {
                foreach ($providers as $provider) {
                    $checked++;

                    $cached = (int) $provider->storage_used_bytes;
                    $actual = $this->aggregator->totalBytesForProvider((int) $provider->id);
                    $delta = $actual - $cached;

                    $attributes = ['storage_recalculated_at' => $now];

                    if ($delta !== 0) {
                        $corrected++;
                        $driftBytes += abs($delta);
                        $attributes['storage_used_bytes'] = $actual;

                        Log::warning('storage:reconcile corrected a drifted provider total — an increment/decrement path was likely missed', [
                            'provider_id' => (int) $provider->id,
                            'cached' => $cached,
                            'actual' => $actual,
                            'delta' => $delta,
                            'by_source' => $this->aggregator->breakdownForProvider((int) $provider->id),
                        ]);
                    }

                    Provider::query()->whereKey($provider->id)->update($attributes);
                }
            });

        return [
            'checked' => $checked,
            'corrected' => $corrected,
            'drift_bytes' => $driftBytes,
        ];
    }
}
