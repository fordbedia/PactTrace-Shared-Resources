<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Console\Commands;

use Illuminate\Console\Command;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\ReconcileStaleEnvelopes;

/**
 * Thin console adapter — same shape as
 * User\Console\Commands\NotifyTrialEnding. Scheduled in
 * backend/routes/console.php; the `scheduler` Docker service already loops
 * `schedule:run` every 60s locally, so no system crontab entry is needed in
 * this repo's dev setup.
 */
class ReconcileStaleDocusignEnvelopes extends Command
{
    protected $signature = 'signature:reconcile-stale-envelopes';

    protected $description = 'Catch up envelopes stuck (draft, or sent/viewed/partially_signed) when DocuSign Connect never delivered — or delayed — the webhook that would have advanced them';

    public function handle(ReconcileStaleEnvelopes $useCase): int
    {
        $result = $useCase->handle();

        $this->info("Checked {$result['checked']} stale envelope(s); reconciled {$result['reconciled']}.");

        return self::SUCCESS;
    }
}
