<?php

namespace App\Http\Requests\General;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;

class RegionRequest extends FormRequest
{
    use HasCatalogRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => array_merge($this->baseRules(), $this->translationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('regions'),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        return [
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'status' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
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
            'translations.*.name' => ['required', 'string', 'min:2', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'country_id' => __('validation.attributes.country_id'),
            'status' => __('validation.attributes.status'),
            'translations' => __('validation.attributes.translations'),
            'translations.*.locale' => __('validation.attributes.translations.*.locale'),
            'translations.*.name' => __('validation.attributes.translations.*.name'),
        ];
    }
}
