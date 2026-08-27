<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation only. Authorization happens in DocumentController against the
 * resolved destination (Matter, if one is given) via DocumentPolicy::create
 * — a FormRequest's authorize() has no natural place to build that
 * parent record, so it stays a pass-through here rather than duplicating
 * the lookup.
 */
class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The extensions DocuSign's eSignature API itself accepts as source
     * documents for envelope creation — PactTrack's own upload validation
     * shouldn't be any stricter than what the document is eventually sent
     * through for signing (see .claude/rules/signature.md).
     *
     * Deliberately validated with `extensions:` (the client-reported file
     * extension) rather than `mimes:` (content sniffed via PHP's fileinfo/
     * libmagic through Symfony's MimeTypes guesser). `.wpd`, `.xps` and
     * `.msg` are not reliably content-sniffed to a matching MIME type across
     * environments/libmagic versions — `mimes:` would silently reject valid
     * files of those types on a host where detection misses. `extensions:`
     * is deterministic regardless of the host's MIME database.
     */
    private const ALLOWED_EXTENSIONS = 'doc,docm,docx,dot,dotm,dotx,htm,html,msg,pdf,rtf,txt,wpd,xhtml,xps';

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:51200', 'extensions:' . self::ALLOWED_EXTENSIONS],
            'matter_id' => ['nullable', 'integer', 'exists:matters,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
        ];
    }
}
