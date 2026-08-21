<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation only for POST /api/signature/documents/{document}/prepare.
 * Authorization happens in EnvelopeController against the resolved
 * Document, same split as StoreFolderRequest/StoreDocumentRequest.
 *
 * `signers` are additional co-signers beyond the document's own client —
 * see PrepareEnvelopeForSignature and .claude/rules/signature.md.
 */
class PrepareEnvelopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'signers' => ['sometimes', 'array', 'max:9'],
            'signers.*.name' => ['required', 'string', 'max:255'],
            'signers.*.email' => ['required', 'email', 'max:255', 'distinct:ignore_case'],
        ];
    }

    /**
     * @return array<array{name: string, email: string}>
     */
    public function coSigners(): array
    {
        return $this->validated('signers', []);
    }
}
