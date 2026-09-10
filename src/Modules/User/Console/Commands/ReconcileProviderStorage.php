<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Console\Commands;

use Illuminate\Console\Command;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\ReconcileProviderStorageUsage;

/**
 * Thin console adapter — same shape as NotifyTrialEnding /
 * ReconcileStaleDocusignEnvelopes: translates the CLI invocation into one
 * call against the use case and prints its result. No business rules here.
 *
 * Scheduled nightly in backend/routes/console.php. The `scheduler` Docker
 * service already loops `schedule:run` every 60s, so no crontab entry is
 * needed in this repo's dev setup.
 */
class ReconcileProviderStorage extends Command
{
    protected $signature = 'storage:reconcile';

    protected $description = 'Recompute every provider\'s true stored-bytes total from the StorageSource registry and correct the cached providers.storage_used_bytes column';

    public function handle(ReconcileProviderStorageUsage $useCase): int
    {
        $result = $useCase->handle();

        $this->info(sprintf(
            'Checked %d provider(s); corrected %d; total drift %d byte(s).',
            $result['checked'],
            $result['corrected'],
            $result['drift_bytes'],
        ));

        return self::SUCCESS;
    }
}
