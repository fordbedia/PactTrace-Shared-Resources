<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * What a client-facing surface (guest signer pages, client portal, emails)
 * may show of a provider's identity — the ONE place the plan rule lives:
 *
 *   Starter          business name as text only, never a logo
 *   Professional/Firm uploaded logo; the business name when none uploaded
 *   every plan       PactTrack's own small "Secured by" mark stays visible
 *                    (a surface concern, not modelled here)
 *
 * Framework-free. `logoUrl` is already a resolved public URL (the caller
 * resolves the stored key through ProviderLogoStorage) and is null whenever
 * the plan doesn't allow a logo — a downgraded tenant's stored file is never
 * deleted, it just stops being surfaced, so an upgrade restores it.
 */
final class ProviderBrand
{
    /** Only ever shown when the business name is blank. */
    public const FALLBACK_NAME = 'Your Provider';

    private function __construct(
        public readonly string $name,
        public readonly ?string $logoUrl,
    ) {
    }

    public static function resolve(?string $businessName, ?string $logoUrl, bool $allowsCustomBranding): self
    {
        $name = trim((string) $businessName);

        return new self(
            $name !== '' ? $name : self::FALLBACK_NAME,
            $allowsCustomBranding && $logoUrl !== null && $logoUrl !== '' ? $logoUrl : null,
        );
    }

    /** @return array{name: string, logo_url: string|null} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'logo_url' => $this->logoUrl];
    }
}
