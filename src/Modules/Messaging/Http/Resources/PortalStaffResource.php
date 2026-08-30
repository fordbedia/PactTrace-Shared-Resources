<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * One contact in the client portal's "Message your team" modal for a
 * matter. Allow-list — a portal client sees only a name and this person's
 * relationship to the matter, never the staffer's email, title, role,
 * permissions or provider internals.
 *
 * `relationship` (`'owner'` | `'assigned'`) is the transient tag
 * GetMatterContactDirectory attaches — the portal renders it as
 * "Owner" / "Assigned to this matter", which is the information a client
 * actually needs here, not a job title.
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
            'relationship' => $this->matter_relationship ?? null,
        ];
    }
}
