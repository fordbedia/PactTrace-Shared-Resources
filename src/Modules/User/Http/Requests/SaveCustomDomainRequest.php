<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body for `PUT /api/v1/branding/custom-domain`. Only shallow shape
 * validation here — the DNS-grammar rule lives in Domain\ValueObjects\CustomDomainName,
 * applied by Application\UseCases\Branding\SaveCustomDomain, same split as
 * UpdateBrandingRequest/Subdomain. `null` clears the domain.
 */
class SaveCustomDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'custom_domain' => ['nullable', 'string', 'max:255'],
        ];
    }
}
