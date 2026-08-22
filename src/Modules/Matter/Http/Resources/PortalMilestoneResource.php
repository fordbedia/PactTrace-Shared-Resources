<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A `Milestone` as the client portal's progress rail renders it — see
 * .claude/rules/matter.md. `state` replaces the frontend's old hardcoded
 * `MILESTONES` array/`done|current|upcoming` values with the real
 * `Milestone.status` (pending/in_progress/completed), mapped 1:1 so
 * `MilestoneStep` on `/portal` doesn't need to change, only what feeds it.
 *
 * @mixin \PactTrackSDK\SharedResources\Modules\Matter\Models\Milestone
 */
class PortalMilestoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'state' => match ($this->status) {
                'completed' => 'done',
                'in_progress' => 'current',
                default => 'upcoming',
            },
            'due_date' => $this->due_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
