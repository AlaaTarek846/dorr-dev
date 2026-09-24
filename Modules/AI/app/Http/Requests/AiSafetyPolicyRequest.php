<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiSafetyPolicyRequest extends FormRequest
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
            'risk_level' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'applies_to' => ['nullable', 'array'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
