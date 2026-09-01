<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * A workspace as the Account Settings "Deactivate Workspace" modal sees it.
 *
 * An allow-list, same reasoning as ProviderResource: `owner_id` and anything
 * internal added to `workspaces` later should not start appearing in an
 * authenticated response by accident. The modal only needs enough to label a
 * row and its Deactivate button.
 *
 * @mixin Workspace
 */
class WorkspaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->workspace_type instanceof WorkspaceType
            ? $this->workspace_type->value
            : (string) $this->workspace_type;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'workspace_type' => $type,
            'client_label' => $this->client_label,
            'engagement_label' => $this->engagement_label,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
