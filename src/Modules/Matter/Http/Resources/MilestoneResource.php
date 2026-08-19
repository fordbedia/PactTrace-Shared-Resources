<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Milestone;

/**
 * @mixin Milestone
 */
class MilestoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matter_id' => $this->matter_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'position' => $this->position,
            'due_date' => $this->due_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
