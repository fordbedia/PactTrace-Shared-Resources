<?php

namespace PactTrackSDK\SharedResources\Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for `POST /api/v1/team/invitations/{token}/accept`.
 *
 * Public by definition — the token in the URL is the credential. Mirrors
 * AcceptClientInvitationRequest / StoreRegistrationRequest so every account
 * that gets created is held to the same password bar (12 chars, confirmed).
 */
class AcceptTeamInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }
}
