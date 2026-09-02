<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * Body for `POST /api/v1/workspaces` (create) and `PUT /api/v1/workspaces/{workspace}`
 * (the onboarding "finish setting up my sole workspace" edit) alike — the two
 * take the same fields. The permission/tenant gate runs in the controller
 * (`Gate::authorize('create' | 'update', ...)`), same split as
 * DeactivateWorkspaceRequest.
 *
 * `provider_id` / `owner_id` are NOT accepted here — they come from the acting
 * user in the controller. Blank `client_label` / `engagement_label` are
 * allowed: on create Workspace's `creating()` hook fills them from the type's
 * preset, on update UpdateWorkspace does the equivalent.
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

        $routeWorkspace = $this->route('workspace');
        if ($routeWorkspace instanceof Workspace) {
            $unique->ignore($routeWorkspace->getKey());
        }

        return [
            'name' => ['required', 'string', 'max:255', $unique],
            'workspace_type' => ['required', 'string', Rule::in(WorkspaceType::values())],
            'client_label' => ['nullable', 'string', 'max:255'],
            'engagement_label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
