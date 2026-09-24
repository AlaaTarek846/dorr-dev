<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiLanguageVariantRequest extends FormRequest
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

        return [
            'language_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:ai_languages,id'],
            'code' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:50'],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:100'],
            'style' => ['nullable', Rule::in(['formal', 'conversational'])],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
