<?php

namespace PactTrackSDK\SharedResources\Modules\Client\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for `POST /api/v1/client-invitations/{token}/accept`.
 *
 * Public by definition — the token in the URL is the credential, not a
 * signed-in actor — and mirrors StoreRegistrationRequest's password rule
 * (12 chars, confirmed) so both signup paths hold clients to the same bar.
 */
class AcceptClientInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
