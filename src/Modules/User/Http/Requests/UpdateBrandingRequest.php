<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body for `PATCH /api/v1/branding`. Every field is `sometimes` — the
 * `/dashboard/branding` save bar sends only what changed. The permission
 * (`ProviderPolicy::manageBranding`) and plan gates
 * (`PlanInfo::allowsCustomBranding` for `primary_color`,
 * `allowsCustomDomain` for `custom_domain`) run in the controller, not here.
 *
 * `subdomain` is only shallow-validated here (length + charset); the
 * DNS-grammar and reserved-word rules live in the Subdomain value object,
 * applied by UpdateProviderBranding.
 */
class UpdateBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $providerId = $this->user()?->provider_id;

        $subdomainUnique = Rule::unique('providers', 'subdomain');
        if ($providerId !== null) {
            $subdomainUnique->ignore($providerId);
        }

        return [
            'business_name' => ['sometimes', 'string', 'max:255'],
            'subdomain' => ['sometimes', 'string', 'min:1', 'max:63', $subdomainUnique],
            'custom_domain' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+$/'],
            'primary_color' => ['sometimes', 'string', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'timezone' => ['sometimes', 'nullable', 'string', Rule::in(timezone_identifiers_list())],
            'locale' => ['sometimes', 'nullable', 'string', 'max:16'],
            'email_sender_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email_reply_to' => ['sometimes', 'nullable', 'email', 'max:255'],
            'email_powered_by_footer' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('primary_color') && is_string($this->input('primary_color'))) {
            $hex = ltrim(trim($this->input('primary_color')), '#');
            $this->merge(['primary_color' => '#' . strtoupper($hex)]);
        }
    }
}
