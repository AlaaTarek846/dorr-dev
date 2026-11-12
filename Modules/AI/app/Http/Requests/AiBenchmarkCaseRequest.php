<?php

namespace Modules\AI\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Models\AiBenchmarkCase;

/** Admin AI benchmark case form - catalog rules per action (no translations: a case holds test data, not labels). */
class AiBenchmarkCaseRequest extends FormRequest
{
    use HasCatalogRules;

    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    protected function prepareForValidation(): void
    {
        // Older callers send is_active; the catalog column is status.
        if (! $this->has('status') && $this->has('is_active')) {
            $this->merge(['status' => $this->input('is_active')]);
        }

        if ($this->has('status') && $this->input('status') !== null) {
            $this->merge(['status' => filter_var($this->input('status'), FILTER_VALIDATE_BOOLEAN)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => $this->baseRules(),
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('ai_benchmark_cases'),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
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
            'status' => ['nullable', 'boolean'],
        ];
    }
}
