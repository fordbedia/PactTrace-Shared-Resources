<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation only for POST /api/v1/signature/matters/{matter}/prepare-all-envelopes.
 * Authorization happens in EnvelopeDetailController against the resolved
 * Matter, same split as PrepareEnvelopeRequest.
 *
 * `signers` is a JSON object keyed by the *internal* document id (a JS
 * object's keys are always strings on the wire — see coSignersByDocumentId()
 * below for where that's cast back to int), each value the same
 * {name, email}[] shape PrepareEnvelopeRequest already validates for the
 * single-document path — the tenant's "who signs each document" step for
 * "Prepare All for Signature" (frontend/app/dashboard/matters/
 * PrepareAllSignatureModal.tsx), collected once for every eligible document
 * before the batch is created, rather than the empty-co-signers-only bulk
 * call this endpoint used to accept exclusively.
 */
class PrepareMatterEnvelopesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'signers' => ['sometimes', 'array'],
            'signers.*' => ['array', 'max:9'],
            'signers.*.*.name' => ['required', 'string', 'max:255'],
            'signers.*.*.email' => ['required', 'email', 'max:255', 'distinct:ignore_case'],
        ];
    }

    /**
     * @return array<int, array<array{name: string, email: string}>> keyed by
     *     the document's internal id
     */
    public function coSignersByDocumentId(): array
    {
        $byDocumentId = [];

        foreach ($this->validated('signers', []) as $documentId => $rows) {
            $byDocumentId[(int) $documentId] = $rows;
        }

        return $byDocumentId;
    }
}
