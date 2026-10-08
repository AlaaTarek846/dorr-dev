<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Models\AiLearnedIntent;
use Modules\AI\Support\AiChatIntent;
use Modules\AI\Support\ArabicTextNormalizer;

class AiLearnedIntentStoreRequest extends FormRequest
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
            'phrase' => ['required', 'string', 'min:2', 'max:120'],
            'intent' => ['required', Rule::in(AiChatIntent::ACTIONS)],
            'match_mode' => ['required', Rule::in([AiLearnedIntent::MODE_PHRASE, AiLearnedIntent::MODE_EXACT])],
            'file_format' => ['nullable', Rule::in(AiChatIntent::FILE_FORMATS)],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $phrase = ArabicTextNormalizer::normalize((string) $this->input('phrase'));

                if (mb_strlen($phrase) < 2) {
                    $validator->errors()->add('phrase', __('ai.learned_intent_phrase_too_short'));

                    return;
                }

                $exists = AiLearnedIntent::query()
                    ->where('phrase', $phrase)
                    ->where('match_mode', $this->input('match_mode'))
                    ->where('intent', $this->input('intent'))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('phrase', __('ai.learned_intent_exists'));
                }
            },
        ];
    }
}
