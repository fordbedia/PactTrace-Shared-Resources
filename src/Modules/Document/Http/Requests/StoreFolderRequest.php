<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation only. Authorization happens in FolderController against the
 * resolved destination (parent Folder, Matter or Client) via
 * FolderPolicy::create — same split as StoreDocumentRequest.
 */
class StoreFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:folders,id'],
            'matter_id' => ['nullable', 'integer', 'exists:matters,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ];
    }
}
