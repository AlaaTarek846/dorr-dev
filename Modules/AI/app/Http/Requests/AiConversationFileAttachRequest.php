<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 10 (doc S28): attaches an EXISTING, already-uploaded AiFile to a
 * conversation by id - this is never a file upload itself (that stays
 * AiFileUploadRequest's job), only a relationship. `file_id` is just a
 * shape check here; AiConversationFileService re-validates ownership
 * for real via AiFileRepository::findForOwner() (doc S28: never trust a
 * client-provided id).
 */
class AiConversationFileAttachRequest extends FormRequest
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
            'file_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
