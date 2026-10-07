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

    /** A cleared video field means "none", never NULL (the columns are NOT NULL). */
    protected function prepareForValidation(): void
    {
        foreach (['video_daily_limit', 'video_max_seconds', 'site_projects_limit', 'site_daily_generations'] as $field) {
            if ($this->has($field) && ($this->input($field) === null || $this->input($field) === '')) {
                $this->merge([$field => 0]);
            }
        }
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
            // NULL = unlimited images a day, 0 = images not in the plan.
            'image_daily_limit' => ['nullable', 'integer', 'min:0', 'max:100000'],
            // 0 = video generation not in the plan.
            'video_daily_limit' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'video_max_seconds' => ['nullable', 'integer', 'min:0', 'max:600'],
            'site_projects_limit' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'site_daily_generations' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
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
