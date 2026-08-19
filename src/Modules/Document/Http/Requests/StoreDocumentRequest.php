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

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:51200', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
            'matter_id' => ['nullable', 'integer', 'exists:matters,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
        ];
    }
}
