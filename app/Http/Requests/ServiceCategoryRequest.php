<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceCategoryRequest extends FormRequest
{
    /**
     * The base_model values known to the platform's service pipelines.
     *
     * @var list<string>
     */
    public const BASE_MODELS = [
        'chat', 'trip', 'booking', 'order', 'delivery', 'service_request',
        'on_demand', 'appointment', 'ticket', 'project',
    ];

    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'requires_provider' => filter_var($this->input('requires_provider', true), FILTER_VALIDATE_BOOLEAN),
            'status' => filter_var($this->input('status', true), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $categoryId = $this->route('service_category');

        return match ($this->route()->getActionMethod()) {
            'changeStatus' => [
                'status' => ['required', 'boolean'],
            ],
            'deleteMultiple' => [
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer', 'distinct', 'exists:service_categories,id'],
            ],
            default => $this->baseRules($categoryId),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(mixed $categoryId): array
    {
        return [
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('service_categories', 'id'),
                Rule::notIn([$categoryId]),
            ],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('service_categories', 'slug')->ignore($categoryId),
            ],
            'icon' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'base_model' => ['nullable', 'string', Rule::in(self::BASE_MODELS)],
            'requires_provider' => ['boolean'],
            'provider_type_label' => ['nullable', 'string', 'max:255'],
            'status' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
