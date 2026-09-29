<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Chat\Services\ChatThemeService;

/**
 * My own settings on one chat — send only what changes.
 */
class ConversationSettingsRequest extends FormRequest
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
            'pinned' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
            'locked' => ['sometimes', 'boolean'],
            'marked_unread' => ['sometimes', 'boolean'],
            'mute' => ['sometimes', 'nullable', Rule::in(['8h', '1w', 'always', 'off'])],
            'theme_id' => ['sometimes', 'nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! app(ChatThemeService::class)->isActive((int) $value)) {
                    $fail(__('chat.errors.theme_invalid'));
                }
            }],
            'custom_theme' => ['sometimes', 'nullable', 'array'],
            'custom_theme.wallpaper' => ['nullable', 'string', 'max:500'],
            'custom_theme.bubble_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6,8}$/'],
            'custom_theme.dim' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
