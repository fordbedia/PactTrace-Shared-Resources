<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Provisioning;

use Illuminate\Support\Facades\Http;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\CustomHostnameProvisioner;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CustomHostnameProvisionResult;

/**
 * Registers a verified custom domain against PactTrack's Cloudflare zone via
 * Cloudflare's Custom Hostnames for SaaS API
 * (https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/),
 * so Cloudflare terminates TLS for the tenant's own hostname at the edge and
 * forwards to PactTrack's origin — no certificate issuance/renewal machinery
 * to build here.
 *
 * NOT wired as the default anywhere yet — `CUSTOM_HOSTNAME_PROVISIONER`
 * defaults to `fake` (see config/services.php) until a real Cloudflare zone
 * and API token exist (`CLOUDFLARE_API_TOKEN` / `CLOUDFLARE_ZONE_ID`, both
 * blank in every `.env.*` today). No direct unit test, by the same
 * convention as StripeBillingProvider (see .claude/rules/plan.md) — real
 * third-party HTTP adapters aren't unit-tested against a live API in this
 * codebase; FakeCustomHostnameProvisioner is what every other test runs
 * against.
 */
final class CloudflareCustomHostnameProvisioner implements CustomHostnameProvisioner
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly string $apiToken,
        private readonly string $zoneId,
    ) {
    }

    public function provision(string $domain): CustomHostnameProvisionResult
    {
        $response = $this->client()->post("/zones/{$this->zoneId}/custom_hostnames", [
            'hostname' => $domain,
            'ssl' => [
                'method' => 'http',
                'type' => 'dv',
            ],
        ]);

        if (! $response->successful()) {
            return new CustomHostnameProvisionResult(
                success: false,
                hostnameId: null,
                sslStatus: 'failed',
                error: $this->firstErrorMessage($response->json()) ?? "Cloudflare returned HTTP {$response->status()}.",
            );
        }

        $result = $response->json('result', []);

        return new CustomHostnameProvisionResult(
            success: true,
            hostnameId: $result['id'] ?? null,
            sslStatus: $result['ssl']['status'] ?? 'pending_validation',
        );
    }

    public function status(string $domain): CustomHostnameProvisionResult
    {
        $response = $this->client()->get("/zones/{$this->zoneId}/custom_hostnames", [
            'hostname' => $domain,
        ]);

        if (! $response->successful()) {
            return new CustomHostnameProvisionResult(
                success: false,
                hostnameId: null,
                sslStatus: 'failed',
                error: $this->firstErrorMessage($response->json()) ?? "Cloudflare returned HTTP {$response->status()}.",
            );
        }

        $result = $response->json('result', [])[0] ?? null;

        if ($result === null) {
            return new CustomHostnameProvisionResult(
                success: false,
                hostnameId: null,
                sslStatus: 'failed',
                error: "No Cloudflare custom hostname registered for [{$domain}].",
            );
        }

        return new CustomHostnameProvisionResult(
            success: true,
            hostnameId: $result['id'] ?? null,
            sslStatus: $result['ssl']['status'] ?? 'pending_validation',
        );
    }

    private function client()
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->apiToken)
            ->acceptJson();
    }

    /**
     * @param mixed $body
     */
    private function firstErrorMessage($body): ?string
    {
        $errors = is_array($body) ? ($body['errors'] ?? []) : [];

        return is_array($errors) && isset($errors[0]['message']) ? (string) $errors[0]['message'] : null;
    }
}
