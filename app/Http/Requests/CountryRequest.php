<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
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
            'dial_code' => ['required', 'string', 'max:10'],
            'phone_starts_with' => ['nullable', 'string', 'max:20'],
            'phone_length' => ['nullable', 'integer', 'min:1', 'max:20'],
            'is_default' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'flag_id' => ['required', 'integer', 'exists:flags,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
        ];
    }
}
