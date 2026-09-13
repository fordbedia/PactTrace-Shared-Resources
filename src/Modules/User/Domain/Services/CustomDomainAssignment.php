<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Services;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CustomDomainStatus;

/**
 * Pure computation of what saving a new `custom_domain` value should do to
 * the verification-lifecycle columns alongside it — no persistence, no I/O.
 *
 * Rules (see .claude/rules/branding.md, "Custom Domain"):
 *  - clearing the domain (null) resets everything to Unverified with no
 *    token;
 *  - setting a domain that differs from the one already stored resets to
 *    Pending with a freshly generated token, clearing any prior
 *    verification/provisioning state — a tenant must not be able to swap in
 *    a new hostname and inherit the old one's verified state;
 *  - re-saving the SAME domain is a no-op on these columns (e.g. hit while
 *    saving an unrelated branding field) — it must not regenerate the token
 *    or bounce an already-verified domain back to pending.
 */
final class CustomDomainAssignment
{
    /**
     * @return array{
     *     custom_domain: ?string,
     *     custom_domain_status: string,
     *     custom_domain_verification_token: ?string,
     *     custom_domain_verified_at: null,
     *     cloudflare_custom_hostname_id: null,
     *     custom_domain_ssl_status: null,
     * }|array{}
     */
    public static function apply(?string $currentDomain, string $newDomain, string $freshToken): array
    {
        if ($newDomain === ($currentDomain ?? '')) {
            return [];
        }

        if ($newDomain === '') {
            return [
                'custom_domain' => null,
                'custom_domain_status' => CustomDomainStatus::Unverified->value,
                'custom_domain_verification_token' => null,
                'custom_domain_verified_at' => null,
                'cloudflare_custom_hostname_id' => null,
                'custom_domain_ssl_status' => null,
            ];
        }

        return [
            'custom_domain' => $newDomain,
            'custom_domain_status' => CustomDomainStatus::Pending->value,
            'custom_domain_verification_token' => $freshToken,
            'custom_domain_verified_at' => null,
            'cloudflare_custom_hostname_id' => null,
            'custom_domain_ssl_status' => null,
        ];
    }
}
