<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/v1/profile/password — the `/profile` password card.
 *
 * The `password` rules mirror the card's on-screen strength checklist
 * one-for-one: at least 8 characters, an uppercase letter, a number, a
 * symbol. `confirmed` pairs it with `password_confirmation`. Verifying the
 * *current* password is the use case's job, not validation's.
 */
class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => [
                'required', 'string', 'confirmed',
                'min:8',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => 'Use at least 8 characters.',
            'password.regex' => 'Include an uppercase letter, a number and a symbol.',
            'password.confirmed' => 'The new password and its confirmation do not match.',
        ];
    }
}
