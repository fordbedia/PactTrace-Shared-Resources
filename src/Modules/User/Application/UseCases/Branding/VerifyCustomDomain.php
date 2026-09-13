<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Branding;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\CustomDomainVerifier;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\CustomHostnameProvisioner;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CustomDomainStatus;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CustomDomainVerificationResult;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use Throwable;

/**
 * `POST /api/v1/branding/custom-domain/verify` — runs the DNS check for the
 * tenant's currently-saved `custom_domain`, synchronously (a DNS lookup pair
 * is fast enough for a manual button click; see the top-level prompt's own
 * "don't queue this one"). Also the per-provider unit of work the hourly
 * `branding:reconcile-custom-domains` job calls for every domain still
 * `pending` — same path, so a provider who fixes their DNS without clicking
 * "Verify" again still gets picked up (see ReconcilePendingCustomDomains).
 *
 * TLS provisioning (Part 2) is triggered automatically, right here, the
 * moment DNS verification first succeeds — never as a separate manual step.
 * A provisioning failure degrades to a stored `custom_domain_ssl_status` of
 * `failed` and is logged; it never undoes the DNS verification that already
 * succeeded (same "best-effort, log, don't unwind the real state change"
 * shape as ResolveEnvelopeBrand — see .claude/rules/signature.md).
 */
final class VerifyCustomDomain
{
    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly CustomDomainVerifier $verifier,
        private readonly CustomHostnameProvisioner $provisioner,
    ) {
    }

    /**
     * @return array{provider: Provider, verification: CustomDomainVerificationResult}
     */
    public function handle(Provider $provider): array
    {
        if ($provider->custom_domain === null) {
            throw new InvalidArgumentException('No custom domain is set for this account.');
        }

        $result = $this->verifier->verify($provider->custom_domain, (string) $provider->custom_domain_verification_token);
        $previousStatus = (string) $provider->custom_domain_status;

        if ($result->error !== null) {
            $provider->custom_domain_status = CustomDomainStatus::Failed->value;
        } elseif ($result->isFullyVerified()) {
            $provider->custom_domain_status = CustomDomainStatus::Verified->value;
            $provider->custom_domain_verified_at = now();
        } else {
            $provider->custom_domain_status = CustomDomainStatus::Pending->value;
        }

        $provider = $this->providers->save($provider);

        if ($provider->custom_domain_status === CustomDomainStatus::Verified->value
            && $previousStatus !== CustomDomainStatus::Verified->value
        ) {
            $this->provision($provider);
        }

        if ($provider->custom_domain_status !== $previousStatus) {
            AuditLog::create([
                'provider_id' => $provider->id,
                'user_id' => null,
                'action' => 'branding.custom_domain_' . $provider->custom_domain_status,
                'auditable_type' => Provider::class,
                'auditable_id' => $provider->id,
                'metadata' => [
                    'domain' => $provider->custom_domain,
                    'previous_status' => $previousStatus,
                    'verification' => $result->toArray(),
                ],
            ]);
        }

        return ['provider' => $provider, 'verification' => $result];
    }

    private function provision(Provider $provider): void
    {
        try {
            $provisionResult = $this->provisioner->provision((string) $provider->custom_domain);
        } catch (Throwable $e) {
            Log::warning('VerifyCustomDomain: TLS provisioning threw for a newly-verified domain.', [
                'provider_id' => $provider->id,
                'domain' => $provider->custom_domain,
                'exception' => $e->getMessage(),
            ]);

            $provider->custom_domain_ssl_status = 'failed';
            $this->providers->save($provider);

            return;
        }

        if (! $provisionResult->success) {
            Log::warning('VerifyCustomDomain: TLS provisioning failed for a newly-verified domain.', [
                'provider_id' => $provider->id,
                'domain' => $provider->custom_domain,
                'error' => $provisionResult->error,
            ]);
        }

        $provider->cloudflare_custom_hostname_id = $provisionResult->hostnameId;
        $provider->custom_domain_ssl_status = $provisionResult->sslStatus;
        $this->providers->save($provider);
    }
}
