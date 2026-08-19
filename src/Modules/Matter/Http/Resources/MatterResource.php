<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Client\Http\Resources\ClientResource;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services\MatterProgressCalculator;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;

/**
 * A provider's matter record, backing `/dashboard/matters` (see
 * .claude/rules/matter.md) — same allow-list shape as ClientResource.
 *
 * @mixin Matter
 */
class MatterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'workspace_id' => $this->workspace_id,
            'client_id' => $this->client_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            /** @see MatterProgressCalculator */
            'progress' => app(MatterProgressCalculator::class)->calculate($this->resource),
            'client' => ClientResource::make($this->whenLoaded('client')),
            'milestones' => MilestoneResource::collection($this->whenLoaded('milestones')),
        ];
    }
}
