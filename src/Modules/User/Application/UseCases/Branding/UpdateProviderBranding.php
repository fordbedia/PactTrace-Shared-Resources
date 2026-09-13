<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Branding;

use Illuminate\Support\Str;
use InvalidArgumentException;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\CustomDomainAssignment;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CustomDomainName;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Subdomain;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * "Save Changes" on `/dashboard/branding` — the non-file fields (accent
 * colour, portal name/subdomain/timezone/locale/custom domain, and the Email
 * Branding block) — and, sharing the same PATCH endpoint, the Firm Details
 * card on `/account-settings` (`firm_email`/`firm_phone`/`address_line1`/
 * `address_line2`, plus `business_name` which both cards edit). One
 * partial-update path; the controller has already run the permission + plan
 * gates and dropped any field the caller isn't allowed to set, so this use
 * case trusts its input shape and only normalises/persists.
 *
 * Client / engagement terminology is NOT handled here — it lives on
 * `workspaces` (client_label / engagement_label) and the screen writes it
 * through the Workspace module's existing `PUT /workspaces/{id}`.
 *
 * A `subdomain` value is run through the {@see Subdomain} value object so the
 * DNS-grammar and reserved-word rules apply here too, not just at sign-up — an
 * `\InvalidArgumentException` surfaces to the controller as a 422.
 *
 * `custom_domain` is ALSO still accepted here for backward compatibility with
 * the pre-verification save flow this endpoint originally shipped with — but
 * (like the dedicated `PUT /branding/custom-domain` — see SaveCustomDomain)
 * it is run through {@see CustomDomainAssignment} rather than blindly
 * `fill()`-ed, so a domain change through EITHER endpoint correctly resets
 * the verification lifecycle (status/token/verified-at) rather than leaving a
 * stale `verified` status pointed at a token for a domain that no longer
 * matches. This was a real gap before: the pre-verification version of this
 * method just wrote `custom_domain` as a plain string with no lifecycle
 * awareness at all, and had no uniqueness check either (a collision would
 * have hit the DB's unique constraint directly as a raw 500).
 */
final class UpdateProviderBranding
{
    /** The only keys this use case will write. */
    private const WRITABLE = [
        'business_name',
        'firm_email',
        'firm_phone',
        'address_line1',
        'address_line2',
        'subdomain',
        'custom_domain',
        'primary_color',
        'timezone',
        'locale',
        'email_sender_name',
        'email_reply_to',
        'email_powered_by_footer',
    ];

    public function __construct(
        private readonly ProviderRepository $providers,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function handle(User $actor, Provider $provider, array $attributes): Provider
    {
        $attributes = array_intersect_key($attributes, array_flip(self::WRITABLE));

        if (array_key_exists('subdomain', $attributes) && $attributes['subdomain'] !== null) {
            $attributes['subdomain'] = Subdomain::fromString((string) $attributes['subdomain'])->value;
        }

        $customDomainRequested = array_key_exists('custom_domain', $attributes);
        $customDomainAttributes = [];

        if ($customDomainRequested) {
            $rawDomain = $attributes['custom_domain'];
            $normalized = is_string($rawDomain) ? trim(strtolower($rawDomain)) : '';
            $normalized = $normalized !== '' ? CustomDomainName::fromString($normalized)->value : '';

            $customDomainAttributes = CustomDomainAssignment::apply(
                currentDomain: $provider->custom_domain,
                newDomain: $normalized,
                freshToken: Str::random(40),
            );

            if ($customDomainAttributes !== [] && $customDomainAttributes['custom_domain'] !== null
                && $this->providers->customDomainTakenByAnother($customDomainAttributes['custom_domain'], (int) $provider->id)
            ) {
                throw new InvalidArgumentException("[{$customDomainAttributes['custom_domain']}] is already in use by another account.");
            }

            unset($attributes['custom_domain']);
        }

        $changed = [];
        foreach ($attributes as $key => $value) {
            if ((string) $provider->getAttribute($key) !== (string) $value) {
                $changed[] = $key;
            }
        }

        if ($customDomainAttributes !== []) {
            $changed[] = 'custom_domain';
        }

        if ($changed === []) {
            return $provider;
        }

        $provider->fill($attributes);
        $provider->fill($customDomainAttributes);
        $provider = $this->providers->save($provider);

        AuditLog::create([
            'provider_id' => $provider->id,
            'user_id' => $actor->id,
            'action' => 'branding.updated',
            'auditable_type' => Provider::class,
            'auditable_id' => $provider->id,
            'metadata' => ['changed' => $changed],
        ]);

        return $provider;
    }
}
