<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * Body for `PUT /api/v1/workspaces/{workspace}`.
 *
 * Distinct from `StoreWorkspaceRequest` on purpose: a create and an edit are
 * different operations that now validate different things. `workspace_type` is
 * `nullable` here — the `/workspaces` Edit modal never sends it (type is
 * immutable, see `UpdateWorkspace`), and the sign-up onboarding screen still
 * sends it to make its one-time practice-type choice. When present it must
 * still be a real type; whether it actually takes effect is `UpdateWorkspace`'s
 * call.
 *
 * The permission/tenant gate runs in the controller
 * (`Gate::authorize('update', $workspace)` after a cross-tenant 404).
 * `provider_id` / `owner_id` are never accepted from the body.
 */
class UpdateWorkspaceRequest extends FormRequest
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
            'workspace_type' => ['nullable', 'string', Rule::in(WorkspaceType::values())],
            'client_label' => ['nullable', 'string', 'max:255'],
            'engagement_label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
