<?php

namespace Modules\AI\Http\Requests;

use App\Http\Requests\Concerns\HasCatalogRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Models\AiLearnedIntent;
use Modules\AI\Support\AiChatIntent;
use Modules\AI\Support\ArabicTextNormalizer;

/**
 * Admin learned-phrase form - catalog rules per action (no translations: the phrase is matched, not translated).
 * The phrase and match type are set once (store); an update changes the intent, the file format or the switch.
 */
class AiLearnedIntentRequest extends FormRequest
{
    use HasCatalogRules;

    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    protected function prepareForValidation(): void
    {
        // Older callers send is_active; the catalog column is status.
        if (! $this->has('status') && $this->has('is_active')) {
            $this->merge(['status' => $this->input('is_active')]);
        }

        if ($this->has('status') && $this->input('status') !== null) {
            $this->merge(['status' => filter_var($this->input('status'), FILTER_VALIDATE_BOOLEAN)]);
        }

        if ($this->has('file_format') && $this->input('file_format') === '') {
            $this->merge(['file_format' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store' => [
                'phrase' => ['required', 'string', 'min:2', 'max:120'],
                'intent' => ['required', Rule::in(AiChatIntent::ACTIONS)],
                'match_mode' => ['required', Rule::in([AiLearnedIntent::MODE_PHRASE, AiLearnedIntent::MODE_EXACT])],
                'file_format' => ['nullable', Rule::in(AiChatIntent::FILE_FORMATS)],
                'status' => ['nullable', 'boolean'],
            ],
            'update' => [
                'intent' => ['sometimes', Rule::in(AiChatIntent::ACTIONS)],
                'file_format' => ['sometimes', 'nullable', Rule::in(AiChatIntent::FILE_FORMATS)],
                'status' => ['sometimes', 'nullable', 'boolean'],
            ],
            'changeStatus' => $this->statusChangeRules(),
            'deleteMultiple' => $this->deleteMultipleRules('ai_learned_intents'),
            default => [],
        };
    }

    public function after(): array
    {
        if ($this->route()->getActionMethod() !== 'store') {
            return [];
        }

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

                // The trash too: the same phrase / intent / mode can exist only once (unique index).
                $existing = AiLearnedIntent::query()->withTrashed()
                    ->where('phrase', $phrase)
                    ->where('match_mode', $this->input('match_mode'))
                    ->where('intent', $this->input('intent'))
                    ->first();

                if ($existing) {
                    $validator->errors()->add('phrase', __($existing->trashed() ? 'ai.learned_intent_in_trash' : 'ai.learned_intent_exists'));
                }
            },
        ];
    }
}
