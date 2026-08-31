<?php

namespace PactTrackSDK\SharedResources\Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validation for `POST /api/v1/team/members` (invite a team member).
 *
 * `authorize()` stays true — the real gate is `Gate::authorize('invite', ...)`
 * in TeamController, matching how AuditLogController authorises (permission
 * check in the controller, not the FormRequest).
 */
class TeamInviteFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise before validating, so `unique` compares the string that will
     * actually be written (same reasoning as StoreRegistrationRequest).
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Can't invite someone who already has a login.
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'title' => ['nullable', 'string', 'max:255'],
            // Provider-side roles only — never accept 'client' here.
            'role' => ['required', Rule::in(['owner', 'staff'])],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Someone with that email already has an account.',
            'role.in' => 'A team member must be invited as an owner or staff.',
        ];
    }
}
