<?php

namespace App\Http\Requests\General;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceCategoryRequest extends FormRequest
{
    use HasCatalogRules;

    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'requires_provider' => filter_var($this->input('requires_provider', false), FILTER_VALIDATE_BOOLEAN),
            'status' => filter_var($this->input('status', true), FILTER_VALIDATE_BOOLEAN),
            'remove_image' => filter_var($this->input('remove_image', false), FILTER_VALIDATE_BOOLEAN),
            'is_login_dashboard' => filter_var($this->input('is_login_dashboard', true), FILTER_VALIDATE_BOOLEAN),
            'is_auto_assign' => filter_var($this->input('is_auto_assign', false), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $categoryId = $this->route('service_category');

        return match ($this->route()->getActionMethod()) {
            'store' => array_merge($this->baseRules($categoryId), $this->translationRules()),
            'update' => array_merge($this->baseRules($categoryId), $this->translationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('service_categories'),
            default => [],
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
                Rule::notIn(array_filter([(int) $categoryId])),
            ],
            'module_name' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('service_categories', 'module_name')->ignore($categoryId),
            ],
            'is_login_dashboard' => ['nullable', 'boolean'],
            'is_auto_assign' => ['nullable', 'boolean'],
            'requires_provider' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,svg', 'max:2048'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function translationRules(): array
    {
        $min = max(count(LanguageRepository::storableLocaleCodes()), 1);

        return [
            'translations' => ['required', 'array', 'min:'.$min],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'parent_id' => __('validation.attributes.parent_id'),
            'module_name' => __('validation.attributes.module_name'),
            'is_login_dashboard' => __('validation.attributes.is_login_dashboard'),
            'is_auto_assign' => __('validation.attributes.is_auto_assign'),
            'requires_provider' => __('validation.attributes.requires_provider'),
            'status' => __('validation.attributes.status'),
            'sort_order' => __('validation.attributes.sort_order'),
            'image' => __('validation.attributes.image'),
            'remove_image' => __('validation.attributes.remove_image'),
            'translations' => __('validation.attributes.translations'),
            'translations.*.locale' => __('validation.attributes.translations.*.locale'),
            'translations.*.name' => __('validation.attributes.translations.*.name'),
        ];
    }
}
