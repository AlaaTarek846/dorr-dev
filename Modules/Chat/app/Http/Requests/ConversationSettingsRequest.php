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
            // My own look for this chat, over the picked / default theme (only I see it). null = back
            // to that theme. The picture is uploaded with POST …/wallpaper; here it can only be
            // removed (`wallpaper: null`). A colour set to null falls back to the theme's.
            'custom_theme' => ['sometimes', 'nullable', 'array'],
            'custom_theme.wallpaper' => ['sometimes', 'nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null) {
                    $fail(__('chat.errors.wallpaper_upload_only'));
                }
            }],
            'custom_theme.sender_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6,8}$/'],
            'custom_theme.receiver_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6,8}$/'],
            'custom_theme.background_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6,8}$/'],
            'custom_theme.dim' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:80'],
        ];
    }
}
