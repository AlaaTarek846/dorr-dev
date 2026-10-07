<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Models\AiUserLanguagePreference;

/**
 * Business gap fix: this row is auto-created with sane defaults the first
 * time an owner chats (AiChatLanguageResolver), but until now nothing -
 * not even the admin - could actually change it, so "reply in a fixed
 * dialect" was unreachable in practice however correct the resolver logic
 * was. Only the admin can set this for now (no self-service user screen
 * yet - a bigger separate frontend build, left as an open item).
 */
class AiUserLanguagePreferenceUpdateRequest extends FormRequest
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
            'response_language_mode' => ['sometimes', Rule::in([
                AiUserLanguagePreference::MODE_FOLLOW_INPUT,
                AiUserLanguagePreference::MODE_FIXED,
            ])],
            'language_id' => ['nullable', 'exists:languages,id'],
            'variant_id' => ['nullable', 'exists:ai_language_variants,id'],
            'auto_detect' => ['sometimes', 'boolean'],
        ];
    }
}
