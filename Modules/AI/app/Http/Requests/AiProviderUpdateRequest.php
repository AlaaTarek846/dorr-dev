<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiProviderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'clear_api_key' => filter_var($this->input('clear_api_key'), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_enabled' => ['nullable', 'boolean'],
            'api_key' => ['nullable', 'string', 'max:1000'],
            'clear_api_key' => ['nullable', 'boolean'],
            'model' => ['nullable', 'string', 'max:150'],
            'base_url' => ['nullable', 'string', 'url', 'max:255'],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'organization' => ['nullable', 'string', 'max:255'],
        ];
    }
}
