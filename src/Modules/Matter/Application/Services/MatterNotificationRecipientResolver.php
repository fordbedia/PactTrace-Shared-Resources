<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Services;

use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * "Who on the provider side should this matter's notifications go to?" — the
 * one place that answer is resolved, so the notification dispatch sites in the
 * Document, Signature and Matter modules can't each invent their own rule.
 *
 * The rule, matching .claude/rules/matter.md, "Matter-level assigned staff":
 * the matter's designated `assigned_staff_id` if set, otherwise the provider
 * owner (always an implicit fallback contact for every matter). `null` only
 * when neither can be resolved (e.g. a provider row with no owner, which
 * shouldn't happen).
 *
 * Framework-light on purpose but not framework-free — it reads Eloquent
 * relations directly, same as the module's other Application services
 * (MilestoneProgressionService). It never sends anything; callers own the
 * Notification::isset() gate and the Mail dispatch.
 */
final class MatterNotificationRecipientResolver
{
    public function forMatter(Matter $matter): ?User
    {
        $matter->loadMissing(['assignedStaff', 'provider.owner']);

        return $matter->assignedStaff ?? $matter->provider?->owner;
    }

    /**
     * The fallback for a document/envelope that has no Matter at all (a
     * "client, no matter" record — see .claude/rules/document.md): just the
     * provider owner.
     */
    public function forProvider(?int $providerId): ?User
    {
        if ($providerId === null) {
            return null;
        }

        return Provider::query()->with('owner')->find($providerId)?->owner;
    }
}
