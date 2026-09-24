<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiLanguageRequest extends FormRequest
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
        $languageId = $this->route('language');

        return [
            'code' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'max:10',
                Rule::unique('ai_languages', 'code')->ignore($languageId),
            ],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:100'],
            'direction' => ['nullable', Rule::in(['ltr', 'rtl'])],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
