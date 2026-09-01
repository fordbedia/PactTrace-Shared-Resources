<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE /api/v1/workspaces/{workspace} — the "Deactivate Workspace" modal's
 * confirmation. `name` and `password` are matched against the acting user's
 * account inside DeactivateWorkspace (validation only checks they were
 * supplied). Same shape as the User module's DeleteAccountRequest.
 */
class DeactivateWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }
}
