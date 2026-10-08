<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiDataPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isUpdate = $this->route()->getActionMethod() === 'update';

        return [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:150'],
            'data_classification' => ['nullable', 'string', 'in:public,internal,confidential,personal,secret'],
            'retention_days' => ['nullable', 'integer', 'min:0'],
            'consent_required' => ['nullable', 'boolean'],
            'minimization_enabled' => ['nullable', 'boolean'],
            'external_provider_allowed' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
