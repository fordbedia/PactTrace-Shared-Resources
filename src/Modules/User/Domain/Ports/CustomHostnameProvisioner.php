<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Ports;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CustomHostnameProvisionResult;

/**
 * TLS provisioning for a verified custom domain (Part 2). Kept behind a
 * port on purpose — the real adapter
 * (Infrastructure\Provisioning\CloudflareCustomHostnameProvisioner) calls a
 * specific paid vendor's API (Cloudflare's Custom Hostnames for SaaS), and
 * nothing in Domain/ or Application/ should ever reference that vendor by
 * name. `Infrastructure\Provisioning\FakeCustomHostnameProvisioner` (bound
 * app-wide in tests, mirroring FakeSignatureProvider — see
 * .claude/rules/signature.md) is what every other test in this codebase runs
 * against.
 */
interface CustomHostnameProvisioner
{
    /** Register `$domain` for TLS termination. Triggered once, right after DNS verification succeeds. */
    public function provision(string $domain): CustomHostnameProvisionResult;

    /** Re-check a previously provisioned hostname's SSL status. */
    public function status(string $domain): CustomHostnameProvisionResult;
}
