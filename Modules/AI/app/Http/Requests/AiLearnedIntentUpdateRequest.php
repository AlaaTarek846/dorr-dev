<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Support\AiChatIntent;

class AiLearnedIntentUpdateRequest extends FormRequest
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
        return [
            'intent' => ['sometimes', Rule::in(AiChatIntent::ACTIONS)],
            'file_format' => ['sometimes', 'nullable', Rule::in(AiChatIntent::FILE_FORMATS)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
