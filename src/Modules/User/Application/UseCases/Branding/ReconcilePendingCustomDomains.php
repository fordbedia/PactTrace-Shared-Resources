<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Branding;

use Illuminate\Support\Facades\Log;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use Throwable;

/**
 * The hourly safety net for a tenant who fixed their DNS but never clicked
 * "Verify" again — re-runs {@see VerifyCustomDomain} for every provider
 * still `pending`. A no-op re-check (DNS still isn't right) is cheap and
 * expected to repeat every run for as long as the domain stays unverified;
 * this mirrors Signature's `ReconcileStaleEnvelopes` safety-net shape (see
 * .claude/rules/signature.md) — the manual "Verify" button remains the
 * primary, immediate path.
 *
 * One provider's lookup failure must never abort the batch — logged and
 * skipped, same as ReconcileStaleEnvelopes.
 */
final class ReconcilePendingCustomDomains
{
    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly VerifyCustomDomain $verify,
    ) {
    }

    /**
     * @return array{checked: int, verified: int, failed: int}
     */
    public function handle(): array
    {
        $checked = 0;
        $verified = 0;
        $failed = 0;

        foreach ($this->providers->withPendingCustomDomainVerification() as $provider) {
            $checked++;

            try {
                $result = $this->verify->handle($provider);
            } catch (Throwable $e) {
                Log::warning('ReconcilePendingCustomDomains: verification threw for a provider; skipping.', [
                    'provider_id' => $provider->id,
                    'domain' => $provider->custom_domain,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            if ($result['verification']->isFullyVerified()) {
                $verified++;
            } elseif ($result['verification']->error !== null) {
                $failed++;
            }
        }

        return ['checked' => $checked, 'verified' => $verified, 'failed' => $failed];
    }
}
