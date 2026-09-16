<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared validation for the Documents page's bulk actions (Move / Zip /
 * Archive) — every one of them takes the same "which rows are selected"
 * payload. Authorization happens per-document in the controller (each row
 * must individually pass the same gate its single-row action already
 * requires), not here.
 */
class BulkDocumentIdsRequest extends FormRequest
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
        ];
    }
}
