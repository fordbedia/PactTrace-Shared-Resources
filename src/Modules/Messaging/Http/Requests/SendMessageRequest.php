<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation only for POST /api/v1/messages. Authorization happens in
 * MessageController against the resolved Client (and Matter, if given) via
 * MessageThreadPolicy::create — a FormRequest's authorize() has no natural
 * place to build those parent records, so it stays a pass-through here,
 * matching StoreDocumentRequest in the Document module.
 */
class SendMessageRequest extends FormRequest
{
    /**
     * Per-file attachment ceiling. Laravel's `max` file rule is in
     * kilobytes, so 5 MB == 5120. This is the server-side enforcement of
     * the same limit the New Message modal pre-checks client-side — see
     * .claude/rules/messaging.md, "Attachment size limit".
     */
    public const MAX_ATTACHMENT_KB = 5120;

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
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'matter_id' => ['nullable', 'integer', 'exists:matters,id'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:' . self::MAX_ATTACHMENT_KB],
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
