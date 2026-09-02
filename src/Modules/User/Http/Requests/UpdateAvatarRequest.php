<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/profile/avatar — the `/profile` identity card's camera button.
 *
 * Multipart, one file field `avatar`. The 5 MB ceiling matches
 * `Messaging\SendMessageRequest::MAX_ATTACHMENT_KB` — an existing limit in the
 * codebase, not a new one. `image` + an explicit `mimes:` list keeps SVG out:
 * the file is served from a public URL straight into an <img>, so a
 * script-bearing SVG would be a stored-XSS vector.
 */
class UpdateAvatarRequest extends FormRequest
{
    /** 5 MB, in kilobytes — Laravel's `max` file rule is KB. */
    private const MAX_KB = 5120;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'avatar' => [
                'required', 'file', 'image',
                'mimes:jpeg,jpg,png,webp',
                'max:' . self::MAX_KB,
            ],
        ];
    }
}
