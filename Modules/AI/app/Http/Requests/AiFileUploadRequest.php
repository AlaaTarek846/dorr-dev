<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Acceptance criteria doc S8: configurable server-side validation - the
 * size limit comes from config('ai.files.max_size_bytes'), never a
 * literal here, so changing it is a config/env change, not a code change.
 */
class AiFileUploadRequest extends FormRequest
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
        $maxKilobytes = (int) ceil(((int) config('ai.files.max_size_bytes', 26214400)) / 1024);

        return [
            'file' => ['required', 'file', 'max:'.$maxKilobytes],
            'conversation_id' => ['nullable', 'integer'],
        ];
    }
}
