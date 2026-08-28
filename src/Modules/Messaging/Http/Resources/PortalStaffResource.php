<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * One staff member in the client portal's contact directory. Allow-list —
 * a portal client sees only a name and a display title, never the
 * staffer's email, role, permissions or provider internals.
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
        ];
    }
}
