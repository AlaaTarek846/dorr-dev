<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\AI\Rules\SafeUploadedFile;

class AiChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user('user_api') ?? $this->user('provider_api'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:8000'],
            'attachment' => [
                'nullable',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,txt,csv,xlsx,m4a',
                // v2.0 requirements doc S15.5: mimes: above only trusts
                // the extension/client-declared type - this checks the
                // file's actual bytes so a renamed executable/script
                // can't ride through as a "photo.jpg".
                new SafeUploadedFile(),
            ],
        ];
    }
}
