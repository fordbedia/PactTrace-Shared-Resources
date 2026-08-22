<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services\MatterProgressCalculator;

/**
 * One row of the client portal's "pick your matter" landing list — see
 * .claude/rules/matter.md. Deliberately thin (no documents/activity/labels)
 * since `PortalMatterController::index()` may return several of these per
 * client; the full `PortalMatterResource` is what `show()` returns once one
 * is picked.
 *
 * @mixin \PactTrackSDK\SharedResources\Modules\Matter\Models\Matter
 */
class PortalMatterSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'status' => $this->status,
            'progress' => app(MatterProgressCalculator::class)->calculate($this->resource),
        ];
    }
}
