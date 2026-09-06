<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/branding/logo — the Portal Logo card's upload / Replace.
 *
 * Multipart, one file field `logo`. 2 MB ceiling matches the artboard's own
 * "Max 2 MB" copy. SVG is deliberately excluded — same reasoning as
 * UpdateAvatarRequest: the file is served from a public URL straight into an
 * <img> on the client portal, so a script-bearing SVG would be a stored-XSS
 * vector. PNG / JPEG / WebP only.
 */
class UpdateProviderLogoRequest extends FormRequest
{
    /** 2 MB, in kilobytes — Laravel's `max` file rule is KB. */
    private const MAX_KB = 2048;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'logo' => [
                'required', 'file', 'image',
                'mimes:jpeg,jpg,png,webp',
                'max:' . self::MAX_KB,
            ],
        ];
    }
}
