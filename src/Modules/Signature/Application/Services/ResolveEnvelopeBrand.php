<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\Services;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * Which DocuSign Signing Brand (if any) an envelope should carry — see
 * .claude/rules/signature.md. Tenants without custom branding get PactTrack's
 * own default brand (DOCUSIGN_DEFAULT_BRAND_ID); tenants whose plan
 * `allowsCustomBranding` get their own (providers.docusign_brand_id). Either
 * can resolve to null — no default configured, or a Professional/Firm tenant
 * who hasn't set one up yet — and that's a normal outcome, not an error:
 * DocusignSignatureProvider::applyBrand() treats a null brandId as "send
 * unbranded" rather than failing.
 *
 * "Does this tenant get their own branding" is asked exactly once, here and on
 * /dashboard/branding, through `Plan::info()->allowsCustomBranding` — no raw
 * `$provider->plan === 'starter'` string check anywhere.
 */
final class ResolveEnvelopeBrand
{
    public function handle(Provider $provider): ?string
    {
        $plan = Plan::tryFrom((string) $provider->plan) ?? Plan::default();

        if (! $plan->info()->allowsCustomBranding) {
            $default = config('services.docusign.default_brand_id');

            return is_string($default) && $default !== '' ? $default : null;
        }

        return $provider->docusign_brand_id;
    }
}
