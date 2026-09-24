<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiLanguageEvaluationRequest extends FormRequest
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
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:150'],
            'language_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:ai_languages,id'],
            'variant_id' => ['nullable', 'integer', 'exists:ai_language_variants,id'],
            'test_case_count' => ['nullable', 'integer', 'min:0'],
            'pass_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', Rule::in(['pending', 'running', 'passed', 'failed'])],
            'evaluated_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
