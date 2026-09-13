<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Provisioning;

use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\CustomHostnameProvisioner;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CustomHostnameProvisionResult;

/**
 * No network calls, no Cloudflare credentials needed — mirrors
 * Signature\Infrastructure\Fake\FakeSignatureProvider (see
 * .claude/rules/signature.md). Bound app-wide in tests
 * (TestCase\BaseTest), and is also the default in local dev
 * (`CUSTOM_HOSTNAME_PROVISIONER=fake`, see config/services.php) until a real
 * Cloudflare zone/token exist.
 *
 * Always succeeds deterministically. `$nextResult` lets an individual test
 * simulate a specific failure without rebinding the whole port.
 */
final class FakeCustomHostnameProvisioner implements CustomHostnameProvisioner
{
    /** @var list<array{method: 'provision'|'status', domain: string}> */
    public array $calls = [];

    public ?CustomHostnameProvisionResult $nextResult = null;

    public function provision(string $domain): CustomHostnameProvisionResult
    {
        $this->calls[] = ['method' => 'provision', 'domain' => $domain];

        return $this->nextResult ?? new CustomHostnameProvisionResult(
            success: true,
            hostnameId: 'fake-hostname-' . md5($domain),
            sslStatus: 'pending_validation',
        );
    }

    public function status(string $domain): CustomHostnameProvisionResult
    {
        $this->calls[] = ['method' => 'status', 'domain' => $domain];

        return $this->nextResult ?? new CustomHostnameProvisionResult(
            success: true,
            hostnameId: 'fake-hostname-' . md5($domain),
            sslStatus: 'active',
        );
    }
}
