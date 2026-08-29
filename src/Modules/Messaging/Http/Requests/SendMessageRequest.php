<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation only for POST /api/v1/messages — the first message of a new
 * conversation from the staff New Message modal. Authorization happens in
 * MessageController against the resolved Matter's client via
 * MessageThreadPolicy::create.
 *
 * `client_id` is deliberately NOT accepted — it is derived from the
 * matter's own `client_id`. `staff_user_id` is not accepted either: a
 * staffer always starts a thread as themselves (the portal directory is
 * the only place a staffer is chosen, and that is a different route).
 */
class SendMessageRequest extends FormRequest
{
    /**
     * Per-file attachment ceiling. Laravel's `max` file rule is in
     * kilobytes, so 5 MB == 5120 — see .claude/rules/messaging.md,
     * "Attachment size limit".
     */
    public const MAX_ATTACHMENT_KB = 5120;

    /**
     * Most files one message may carry. A message is not a second
     * document-upload pipeline — the Document module exists for genuine
     * multi-file case management. Enforced server-side regardless of the
     * frontend; see .claude/rules/messaging.md, "Attachment size limit".
     */
    public const MAX_ATTACHMENTS = 5;

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
            'matter_id' => ['required', 'integer', 'exists:matters,id'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'attachments' => ['nullable', 'array', 'max:' . self::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'max:' . self::MAX_ATTACHMENT_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attachments.max' => 'You can attach up to ' . self::MAX_ATTACHMENTS . ' files per message.',
            'attachments.*.max' => 'Each attachment must be 5 MB or smaller.',
        ];
    }
}
