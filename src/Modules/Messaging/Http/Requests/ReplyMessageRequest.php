<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation only for a reply into an existing thread —
 * POST /api/v1/messages/threads/{thread} (staff) and its portal mirror.
 * The thread is resolved by route-model binding and authorised
 * (MessageThreadPolicy::reply) in the controller.
 */
class ReplyMessageRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:20000'],
            'attachments' => ['nullable', 'array', 'max:' . SendMessageRequest::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'max:' . SendMessageRequest::MAX_ATTACHMENT_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attachments.max' => 'You can attach up to ' . SendMessageRequest::MAX_ATTACHMENTS . ' files per message.',
            'attachments.*.max' => 'Each attachment must be 5 MB or smaller.',
        ];
    }
}
