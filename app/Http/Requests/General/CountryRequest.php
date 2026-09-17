<?php

namespace App\Http\Requests\General;

use App\Http\Requests\Concerns\HasCatalogRules;
use App\Repositories\General\LanguageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CountryRequest extends FormRequest
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
        $id = $this->route('country');

        return match ($this->route()->getActionMethod()) {
            'store' => array_merge($this->baseRules($id), $this->translationRules()),
            'update' => array_merge($this->baseRules($id), $this->translationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('countries'),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(mixed $id): array
    {
        return [
            'code' => ['required', 'string', 'max:10', Rule::unique('countries', 'code')->ignore($id)],
            'code_alpha3' => ['nullable', 'string', 'max:10'],
            'dial_code' => ['required', 'string', 'regex:/^\+?[0-9]{1,4}$/'],
            'phone_starts_with' => ['required', 'regex:/^[0-9]{1,2}$/'],
            'phone_length' => ['required', 'integer', 'min:5', 'max:15'],
            'is_default' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'flag_id' => ['required', 'integer', 'exists:flags,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
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
            'translations.*.name' => ['required', 'string', 'min:2', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => __('validation.attributes.code'),
            'code_alpha3' => __('validation.attributes.code_alpha3'),
            'dial_code' => __('validation.attributes.dial_code'),
            'phone_starts_with' => __('validation.attributes.phone_starts_with'),
            'phone_length' => __('validation.attributes.phone_length'),
            'flag_id' => __('validation.attributes.flag_id'),
            'currency_id' => __('validation.attributes.currency_id'),
            'status' => __('validation.attributes.status'),
            'translations' => __('validation.attributes.translations'),
            'translations.*.locale' => __('validation.attributes.translations.*.locale'),
            'translations.*.name' => __('validation.attributes.translations.*.name'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dial_code.regex' => __('validation.digits_between', [
                'attribute' => __('validation.attributes.dial_code'),
                'min' => 1,
                'max' => 4,
            ]),
            'phone_starts_with.regex' => __('validation.digits_between', [
                'attribute' => __('validation.attributes.phone_starts_with'),
                'min' => 1,
                'max' => 2,
            ]),
        ];
    }
}
