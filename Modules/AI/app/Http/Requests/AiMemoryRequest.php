<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\AI\Services\AiMemoryService;

/** One memory the person writes or edits themselves (spec 143). */
class AiMemoryRequest extends FormRequest
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
            'content' => ['required', 'string', 'max:'.AiMemoryService::MAX_LENGTH],
        ];
    }
}
