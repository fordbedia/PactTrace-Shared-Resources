<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/notification-preferences/{key} — one row's auto-save on the
 * `/notification` screen.
 *
 * Only `email` is sent by the product today; `in_app` / `sms` are accepted
 * (`sometimes`) so the same endpoint serves those channels when they ship. At
 * least one channel must be present — an empty body is a no-op the client
 * should never send.
 */
class UpdateNotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'email' => ['sometimes', 'boolean'],
            'in_app' => ['sometimes', 'boolean'],
            'sms' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->hasAny(['email', 'in_app', 'sms'])) {
                $validator->errors()->add('email', 'Provide at least one channel to update.');
            }
        });
    }

    /**
     * @return array{email?: bool, in_app?: bool, sms?: bool}
     */
    public function channels(): array
    {
        return array_map(
            static fn ($v): bool => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            $this->only(['email', 'in_app', 'sms']),
        );
    }
}
