<?php

namespace Modules\AI\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\AI\Models\AiRoutingRule;

/** Admin AI routing rule form - catalog rules per action (no translations: a rule has no text to translate). */
class AiRoutingRuleRequest extends FormRequest
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

        foreach (['intent_id', 'provider_id', 'model_key'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $this->merge([$field => null]);
            }
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
            'deleteMultiple' => $this->deleteMultipleRules('ai_routing_rules'),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        $isUpdate = $this->route()->getActionMethod() === 'update';
        $current = $isUpdate ? AiRoutingRule::withTrashed()->find($this->route('rule')) : null;

        return [
            'routing_policy_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', $this->liveOrCurrent('ai_routing_policies', $current?->routing_policy_id)],
            'intent_id' => ['nullable', 'integer', $this->liveOrCurrent('ai_intents', $current?->intent_id)],
            'provider_id' => ['nullable', 'integer', 'exists:ai_providers,id'],
            'model_key' => ['nullable', 'string', 'max:150'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'selection_config' => ['nullable', 'array'],
            'status' => ['nullable', 'boolean'],
        ];
    }

    /** A row in the trash can't be picked - unless the rule already points at it (saving it again keeps it). */
    private function liveOrCurrent(string $table, ?int $current): Exists
    {
        return Rule::exists($table, 'id')->where(fn ($q) => $q->whereNull('deleted_at')->when($current, fn ($q) => $q->orWhere('id', $current)));
    }
}
