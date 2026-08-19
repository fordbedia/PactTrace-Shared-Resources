<?php

namespace PactTrackSDK\SharedResources\Modules\User\Console\Commands;

use Illuminate\Console\Command;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\ProcessTrialExpirations;

/**
 * Thin console adapter — same rule as an HTTP controller (see top-level
 * CLAUDE.md's hexagonal note): translates the CLI invocation into one call
 * against ProcessTrialExpirations and prints its result. No business rules
 * live here.
 *
 * Scheduled daily at 09:00 in backend/routes/console.php. In this repo's
 * Docker setup the `scheduler` service already loops `schedule:run` every
 * 60s, so no system crontab entry is needed locally; a bare-metal deploy
 * would need the usual `* * * * * php artisan schedule:run` cron line.
 */
class NotifyTrialEnding extends Command
{
    protected $signature = 'subscriptions:notify-trial-ending';

    protected $description = 'Flip expired trials to `expired` and record trial-ending-soon audit events';

    public function handle(ProcessTrialExpirations $useCase): int
    {
        $result = $useCase->handle();

        $this->info("Trials expired: {$result['expired']}. Ending soon (within warning window): {$result['ending_soon']}.");

        return self::SUCCESS;
    }
}
