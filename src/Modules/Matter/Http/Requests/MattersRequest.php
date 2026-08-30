<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Query\AssignableMatterStaff;

class MattersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Record-level authorization stays in the controller
     * (`Gate::authorize('update', $matter)` / `MatterPolicy`), same as the
     * rest of the module.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * One request class serves both `store` (POST — every field required)
     * and `update` (PATCH/PUT — a partial body, e.g. just `assigned_staff_id`
     * from the Matter Detail page's inline control). `sometimes` on update
     * keeps this a single validation path rather than a second FormRequest
     * — see .claude/rules/matter.md.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
			'id' => 'nullable|integer',
			'provider_id' => 'nullable|exists:providers,id',
			'workspace_id' => 'nullable|exists:workspaces,id',
			'client_id' => [$required, 'exists:clients,id'],
			'name' => [$required, 'string'],
			'description' => 'nullable|string',
			'status' => [$required],
			'start_date' => 'nullable|date_format:Y-m-d',
			'due_date' => 'nullable|date_format:Y-m-d',
			// Assign/reassign the matter's point of contact. `exists` alone
			// would let another tenant's user id through, so the real check
			// is that the id belongs to a provider-side user (owner or
			// staff) of the ACTING provider — resolved server-side, never
			// trusted from the request. null clears the assignment.
			'assigned_staff_id' => ['nullable', 'integer', $this->assignedStaffBelongsToTenant()],
        ];
    }

    private function assignedStaffBelongsToTenant(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null) {
                return;
            }

            $providerId = $this->user()?->provider_id;

            if ($providerId === null
                || ! app(AssignableMatterStaff::class)->existsForProvider((int) $value, (int) $providerId)
            ) {
                $fail('The selected staff member is not available on this account.');
            }
        };
    }
}
