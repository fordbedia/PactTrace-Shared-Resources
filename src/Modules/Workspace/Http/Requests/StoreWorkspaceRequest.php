<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;

/**
 * Body for `POST /api/v1/workspaces` (create). Editing has its own
 * `UpdateWorkspaceRequest` now — a create and an edit validate different
 * things (notably `workspace_type` is required here, optional there). The
 * permission/tenant gate runs in the controller
 * (`Gate::authorize('create', ...)`), same split as DeactivateWorkspaceRequest.
 *
 * `provider_id` / `owner_id` are NOT accepted here — they come from the acting
 * user in the controller. Blank `client_label` / `engagement_label` are
 * allowed: Workspace's `creating()` hook fills them from the type's preset.
 *
 * `name` is unique per provider (`workspaces` has `unique(provider_id, name)`).
 * The rule queries the raw table, so it also catches a clash with a
 * *deactivated* workspace's name — which the DB constraint would 500 on
 * otherwise, since a soft delete leaves the row.
 */
class StoreWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $providerId = $this->user()?->provider_id;

        $unique = Rule::unique('workspaces', 'name');

        if ($providerId !== null) {
            $unique->where('provider_id', $providerId);
        }

        return [
            'name' => ['required', 'string', 'max:255', $unique],
            'workspace_type' => ['required', 'string', Rule::in(WorkspaceType::values())],
            'client_label' => ['nullable', 'string', 'max:255'],
            'engagement_label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
