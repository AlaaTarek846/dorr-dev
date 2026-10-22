<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\AI\Services\AiContextContinuationService;

/** "Continue in a new chat" from one or more of the person's conversations (spec 123, 131). */
class AiContinueConversationRequest extends FormRequest
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
        return [
            'conversation_ids' => ['required', 'array', 'min:1', 'max:'.AiContextContinuationService::MAX_SOURCES],
            'conversation_ids.*' => ['integer', 'distinct'],
        ];
    }
}
