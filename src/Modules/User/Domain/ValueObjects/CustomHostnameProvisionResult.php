<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * The outcome of a {@see \PactTrackSDK\SharedResources\Modules\User\Domain\Ports\CustomHostnameProvisioner}
 * call (Part 2 — TLS provisioning). Deliberately vendor-neutral: nothing
 * outside `Infrastructure/Provisioning/` should ever see a raw Cloudflare
 * response shape.
 */
final class CustomHostnameProvisionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $hostnameId,
        public readonly string $sslStatus,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * @return array{success: bool, hostname_id: ?string, ssl_status: string, error: ?string}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'hostname_id' => $this->hostnameId,
            'ssl_status' => $this->sslStatus,
            'error' => $this->error,
        ];
    }
}
