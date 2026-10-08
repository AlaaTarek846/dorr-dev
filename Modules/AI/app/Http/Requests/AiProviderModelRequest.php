<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\AI\Enums\AiModelCapability;

class AiProviderModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isCreate = $this->isMethod('post') && $this->route('model') === null;

        return [
            'model_key' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:191'],
            'display_name' => ['nullable', 'string', 'max:191'],
            'capabilities' => [$isCreate ? 'required' : 'sometimes', 'array', 'min:1'],
            'capabilities.*' => ['string', 'in:'.implode(',', AiModelCapability::values())],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            // Admin-correctable metadata (see the temperature-support/
            // limits migration's docblock) - 'sometimes' so an update
            // call that never mentions these leaves them exactly as they
            // are, never resets them to null.
            'temperature_supported' => ['sometimes', 'boolean'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_output_tokens' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'context_window' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
