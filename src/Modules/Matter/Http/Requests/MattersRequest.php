<?php

namespace PactTraceSDK\SharedResources\Modules\Matter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MattersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
			'id' => 'nullable|integer',
			'provider_id' => 'nullable|exists:providers,id',
			'workspace_id' => 'nullable|exists:workspaces,id',
			'client_id' => 'required|exists:clients,id',
			'name' => 'required|string',
			'description' => 'nullable|string',
			'status' => 'required',
			'start_date' => 'nullable|date_format:Y-m-d',
			'due_date' => 'nullable|date_format:Y-m-d',
        ];
    }
}
