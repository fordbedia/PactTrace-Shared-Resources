<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Documents page's bulk "Move" modal payload. `folder_id` is the one
 * destination folder every selected document is moved into — the modal
 * only ever offers real, already-created folders (see
 * .claude/rules/document.md), never an "unfiled" option, so this is
 * required rather than nullable like StoreFolderRequest's `parent_id`.
 */
class MoveDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_ids' => ['required', 'array', 'min:1'],
            'document_ids.*' => ['integer', 'distinct', 'exists:documents,id'],
            'folder_id' => ['required', 'integer', 'exists:folders,id'],
        ];
    }
}
