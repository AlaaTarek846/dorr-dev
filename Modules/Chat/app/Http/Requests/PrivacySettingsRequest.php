<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Chat\Enums\PrivacyAudience;

class PrivacySettingsRequest extends FormRequest
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
        $audience = Rule::enum(PrivacyAudience::class);

        return [
            'last_seen' => ['sometimes', $audience],
            'profile_photo' => ['sometimes', $audience],
            'who_can_message' => ['sometimes', $audience],
            'who_can_add_to_groups' => ['sometimes', $audience],
            'who_can_call' => ['sometimes', $audience],
            'read_receipts' => ['sometimes', 'boolean'],
            'block_screenshots' => ['sometimes', 'boolean'],
            'notification_preview' => ['sometimes', 'boolean'],
        ];
    }
}
