<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiConversationInstructionRequest extends FormRequest
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
            'conversation_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:ai_conversations,id'],
            'source_type' => ['nullable', Rule::in(['user', 'system', 'template'])],
            'instruction' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:5000'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
