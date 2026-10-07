<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Root-cause fix (languages consolidation): this screen no longer
 * creates/edits/deletes a language's code/name/direction (that is the
 * general Languages admin screen's job, since those fields also affect
 * the website and dashboard) - the only thing it can change is whether
 * the AI assistant is allowed to use this (already-existing) language.
 */
class AiLanguageRequest extends FormRequest
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
            'ai_enabled' => ['required', 'boolean'],
        ];
    }
}
