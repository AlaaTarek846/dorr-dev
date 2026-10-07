<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\AI\Http\Requests\Concerns\TranslatesSiteAttributes;
use Illuminate\Validation\Rule;

class AiSiteHostingPlanRequest extends FormRequest
{
    use TranslatesSiteAttributes;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $plan = $this->route('plan');
        $id = is_object($plan) ? $plan->id : $plan;

        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('ai_site_hosting_plans', 'code')->ignore($id)],
            'period' => ['required', Rule::in(['monthly', 'yearly'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
