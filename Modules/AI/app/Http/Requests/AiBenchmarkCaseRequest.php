<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Models\AiBenchmarkCase;

class AiBenchmarkCaseRequest extends FormRequest
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
            'domain_key' => ['nullable', 'string', 'max:60'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'language' => ['nullable', 'string', 'max:5'],
            'task_type' => ['nullable', 'string', 'max:60'],
            'difficulty' => [$isUpdate ? 'sometimes' : 'required', Rule::in([
                AiBenchmarkCase::DIFFICULTY_EASY,
                AiBenchmarkCase::DIFFICULTY_HARD,
                AiBenchmarkCase::DIFFICULTY_ADVERSARIAL,
                AiBenchmarkCase::DIFFICULTY_INSUFFICIENT_INFO,
            ])],
            'risk_level' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'prompt' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:5000'],
            'expected_behavior' => [$isUpdate ? 'sometimes' : 'required', Rule::in([
                AiBenchmarkCase::BEHAVIOR_ANSWER,
                AiBenchmarkCase::BEHAVIOR_ABSTAIN,
                AiBenchmarkCase::BEHAVIOR_ASK_CLARIFICATION,
            ])],
            'expected_answer_keywords' => ['nullable', 'array'],
            'expected_answer_keywords.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
