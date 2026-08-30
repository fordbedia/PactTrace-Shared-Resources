<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Action;

use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The curated contact set the client portal's "Message your team" modal
 * offers for one matter — see .claude/rules/matter.md, "Matter-level
 * assigned staff", and .claude/rules/messaging.md.
 *
 * This replaced the old flat "every provider-side user" directory: for a
 * given matter it returns exactly
 *
 *   1. the provider's owner — always, as the built-in fallback contact
 *      (derived from `providers.owner_user_id`, never stored on the matter);
 *   2. the matter's assigned staff member — only when `assigned_staff_id`
 *      is set;
 *
 * de-duplicated (if the assigned staff member *is* the owner, one row).
 * Each returned `User` carries a transient `matter_relationship`
 * (`'owner'` | `'assigned'`) so the portal can show the person's
 * relationship to this matter instead of a job title.
 *
 * The caller loads `provider.owner` and `assignedStaff` on the matter
 * before calling this.
 */
class GetMatterContactDirectory
{
    /**
     * @return Collection<int, User>
     */
    public function handle(Matter $matter): Collection
    {
        $owner = $matter->provider?->owner;
        $assigned = $matter->assignedStaff;

        /** @var Collection<int, User> $contacts */
        $contacts = new Collection();

        if ($owner !== null) {
            $owner->matter_relationship = 'owner';
            $contacts->push($owner);
        }

        if ($assigned !== null && ($owner === null || $assigned->id !== $owner->id)) {
            $assigned->matter_relationship = 'assigned';
            $contacts->push($assigned);
        }

        return $contacts->values();
    }
}
