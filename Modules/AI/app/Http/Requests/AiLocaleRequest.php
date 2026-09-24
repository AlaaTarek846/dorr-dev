<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiLocaleRequest extends FormRequest
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
        $localeId = $this->route('locale');

        return [
            'language_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:ai_languages,id'],
            'code' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'max:20',
                Rule::unique('ai_locales', 'code')->ignore($localeId),
            ],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:100'],
            'settings' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
