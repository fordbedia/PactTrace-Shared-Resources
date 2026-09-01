<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE /api/v1/profile — the `/profile` delete-account modal's
 * confirmation. `name` and `password` are matched against the acting account
 * inside DeleteOwnAccount (validation only checks they were supplied).
 */
class DeleteAccountRequest extends FormRequest
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
