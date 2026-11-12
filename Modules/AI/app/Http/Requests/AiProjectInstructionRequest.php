<?php

namespace Modules\AI\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin AI project instruction form - catalog rules per action (no translations: the instruction is a prompt sent to
 * the AI as it is).
 */
class AiProjectInstructionRequest extends FormRequest
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

        // A cleared priority means 0, never NULL (the column is NOT NULL).
        if ($this->has('priority') && ($this->input('priority') === null || $this->input('priority') === '')) {
            $this->merge(['priority' => 0]);
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
            'deleteMultiple' => $this->deleteMultipleRules('ai_project_instructions'),
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
            'project_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:ai_projects,id'],
            'instruction' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:5000'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'boolean'],
        ];
    }
}
