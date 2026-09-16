<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Documents page's per-row "Reassign Matter" action (the pen icon) —
 * see .claude/rules/document.md, "Reassign Matter from the Documents page".
 * `matter_id` is nullable (unfiling a document is a real, supported state —
 * see [[matter]]'s "client, no matter" case) — a bare `exists:matters,id`
 * says nothing about tenancy, same as MoveDocumentsRequest's `folder_id`;
 * DocumentController::reassignMatter() resolves the destination inside the
 * acting provider itself and 422s a foreign one.
 */
class ReassignDocumentMatterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'matter_id' => ['nullable', 'integer', 'exists:matters,id'],
        ];
    }
}
