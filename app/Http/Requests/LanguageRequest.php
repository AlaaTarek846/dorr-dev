<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LanguageRequest extends FormRequest
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
        $id = $this->route('language');

        return match ($this->route()->getActionMethod()) {
            'store' => array_merge($this->baseRules($id), $this->translationRules()),
            'update' => array_merge($this->baseRules($id), $this->translationRules()),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('languages'),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(mixed $id): array
    {
        return [
            'code' => ['required', 'string', 'max:10', Rule::unique('languages', 'code')->ignore($id)],
            'direction' => ['required', Rule::in(['rtl', 'ltr'])],
            'is_default_website' => ['nullable', 'boolean'],
            'is_default_dashboard' => ['nullable', 'boolean'],
            'stores_translation' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'flag_id' => ['required', 'integer', 'exists:flags,id'],
        ];
    }
}
