<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Models\AiUserLanguagePreference;

/**
 * Self-service counterpart of AiUserLanguagePreferenceUpdateRequest: the
 * admin request lets an admin change *any* owner's row by numeric id; this
 * one lets the authenticated user change only their own row, so it never
 * takes an id from the route at all - the controller resolves the row from
 * the authenticated user. Whether "fixed" mode actually has a language to
 * be fixed to is checked in the controller, where the existing row (and
 * not just this request's payload) is available.
 */
class AiUserLanguagePreferenceSelfUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('user_api');
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
        ];
    }
}
