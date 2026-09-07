<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * One contact in the client portal's "Message your team" modal for a
 * matter. Allow-list — a portal client sees a name and the person's job
 * `title` (a client-facing display label, see .claude/rules/messaging.md,
 * "Staff job title"), never their email, role, permissions or provider
 * internals.
 *
 * `title` is what the portal renders under the name (per Ed, 2026-09-06:
 * show the job title, not "Owner" / "Assigned to this matter").
 * `relationship` (`'owner'` | `'assigned'`, the transient tag
 * GetMatterContactDirectory attaches) is still sent so the frontend has a
 * neutral fallback when a contact has no `title` set.
 *
 * @mixin User
 */
class PortalStaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'title' => $this->title,
            'relationship' => $this->matter_relationship ?? null,
        ];
    }
}
