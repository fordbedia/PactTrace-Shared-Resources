<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * One provider-side user offered in a matter's "Assigned Staff" picker
 * (New Matter drawer + Matter Detail page). Allow-list — staff-facing, so
 * `email` is fine to omit here (the picker only shows a name + role label),
 * and nothing internal (permissions, provider row) is exposed.
 *
 * `is_owner` is the transient flag `EloquentAssignableMatterStaff` tags each
 * row with, so the picker can render "Jane Doe (Owner)" vs "(Staff)"
 * without a second lookup.
 *
 * @mixin User
 */
class AssignableStaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'title' => $this->title,
            'is_owner' => (bool) ($this->is_owner ?? false),
        ];
    }
}
