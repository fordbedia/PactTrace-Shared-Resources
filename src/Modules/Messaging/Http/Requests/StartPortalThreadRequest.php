<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation only for POST /api/v1/portal/matters/{matter}/message-threads
 * — a client starting a conversation from the portal staff directory. The
 * matter (hence provider + client) comes from the route; only the chosen
 * staff member and the message itself are in the body.
 *
 * `staff_user_id` existing is checked here; that it belongs to the
 * client's OWN provider (and is provider-side, not another client) is
 * checked in PortalMessagingController — the `exists` rule alone would let
 * a foreign staffer through.
 */
class StartPortalThreadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'staff_user_id' => ['required', 'integer', 'exists:users,id'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:' . SendMessageRequest::MAX_ATTACHMENT_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attachments.*.max' => 'Each attachment must be 5 MB or smaller.',
        ];
    }
}
