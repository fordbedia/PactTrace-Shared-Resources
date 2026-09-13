<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Console\Commands;

use Illuminate\Console\Command;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Branding\ReconcilePendingCustomDomains as ReconcilePendingCustomDomainsUseCase;

/**
 * Thin console adapter — same shape as ReconcileProviderStorage /
 * ReconcileStaleDocusignEnvelopes. Scheduled hourly in
 * backend/routes/console.php.
 */
class ReconcilePendingCustomDomains extends Command
{
    protected $signature = 'branding:reconcile-custom-domains';

    protected $description = 'Re-run DNS verification for every provider whose custom domain is still pending';

    public function handle(ReconcilePendingCustomDomainsUseCase $useCase): int
    {
        $result = $useCase->handle();

        $this->info(sprintf(
            'Checked %d pending domain(s); %d newly verified, %d failed.',
            $result['checked'],
            $result['verified'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
