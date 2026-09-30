<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiPlanRequest extends FormRequest
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
        $planId = $this->route('plan');

        return [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:150'],
            'code' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'max:100',
                Rule::unique('ai_plans', 'code')->ignore($planId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'usage_minutes' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'min:0'],
            'cooldown_minutes' => ['nullable', 'integer', 'min:0'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_trial' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'badge' => ['nullable', 'string', 'max:60'],
            'features' => ['nullable', 'array', 'max:12'],
            'features.*' => ['string', 'max:150'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
