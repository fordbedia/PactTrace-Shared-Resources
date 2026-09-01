<?php

namespace PactTrackSDK\SharedResources\Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for `PATCH /api/v1/team/members/{member}` (change a teammate's
 * role).
 *
 * `authorize()` stays true — the real gate is
 * `Gate::authorize('manageMembers', User::class)` in TeamController (owner
 * identity, not merely a permission), matching how the invite/resend actions
 * authorise in the controller rather than the FormRequest.
 *
 * Same allow-list as inviting: `owner` is never assignable through this flow
 * (one per tenant, set at sign-up — owner handoff is a separate action that
 * does not exist yet) and `client` is a different identity entirely.
 */
class TeamMemberRoleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['admin', 'staff'])],
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'A team member can only be set to admin or staff.',
        ];
    }
}
