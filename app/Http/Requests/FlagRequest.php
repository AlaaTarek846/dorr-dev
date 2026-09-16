<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FlagRequest extends FormRequest
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
        $id = $this->route('flag');

        return match ($this->route()->getActionMethod()) {
            'store' => array_merge($this->baseRules($id), $this->translationRules()),
            'update' => array_merge($this->baseRules($id), $this->translationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('flags'),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(mixed $id): array
    {
        return [
            'code' => ['required', 'string', 'max:3', Rule::unique('flags', 'code')->ignore($id)],
            'status' => ['nullable', 'boolean'],
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
            'translations.*.name' => ['required', 'string', 'min:3', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => __('validation.attributes.code'),
            'status' => __('validation.attributes.status'),
            'translations' => __('validation.attributes.translations'),
            'translations.*.locale' => __('validation.attributes.translations.*.locale'),
            'translations.*.name' => __('validation.attributes.translations.*.name'),
        ];
    }
}
