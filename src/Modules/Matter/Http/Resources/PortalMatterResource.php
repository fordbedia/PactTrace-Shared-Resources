<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services\MatterActivityFeedBuilder;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services\MatterProgressCalculator;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;

/**
 * Backs the client portal's matter detail view
 * (`/portal/matter/{matterId}` -> `public_id`, see .claude/rules/matter.md).
 * Distinct from the provider-facing `MatterResource` for the same reason
 * `SigningController` is kept separate from `EnvelopeController` (see
 * .claude/rules/signature.md): different audience, different payload — this
 * one exposes only `public_id`, never the internal auto-increment `id`,
 * and adds the workspace's client-facing vocabulary
 * (.claude/rules/workspace.md) plus the merged activity feed neither
 * dashboard resource needs.
 *
 * Explicitly pins the workspace context to *this matter's own*
 * `workspace_id` before resolving labels, rather than trusting whatever
 * `CurrentWorkspace` would otherwise resolve (a route `{workspace}` param
 * or session value) — neither exists on a portal request, and a provider
 * with more than one workspace would otherwise get the wrong one's wording.
 * See CurrentWorkspace::setId()'s own docblock — this is exactly what
 * explicit pinning is for.
 *
 * @mixin \PactTrackSDK\SharedResources\Modules\Matter\Models\Matter
 */
class PortalMatterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        app(CurrentWorkspace::class)->setId($this->workspace_id);

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'status' => $this->status,
            /** @see MatterProgressCalculator */
            'progress' => app(MatterProgressCalculator::class)->calculate($this->resource),
            'provider_name' => $this->whenLoaded('provider', fn () => $this->provider?->business_name),
            'labels' => [
                'client' => workspace_label('client'),
                'engagement' => workspace_label('engagement'),
            ],
            'milestones' => PortalMilestoneResource::collection($this->whenLoaded('milestones')),
            'documents' => PortalDocumentResource::collection($this->whenLoaded('documents')),
            /** @see MatterActivityFeedBuilder */
            'activity' => app(MatterActivityFeedBuilder::class)->build($this->resource),
        ];
    }
}
