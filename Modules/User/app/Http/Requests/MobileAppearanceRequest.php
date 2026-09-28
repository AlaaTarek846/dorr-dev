<?php

namespace Modules\User\Http\Requests;

use App\Support\Mobile\MobileColorTokens;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MobileAppearanceRequest extends FormRequest
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
        return array_merge(
            [
                'uses_default_colors' => ['nullable', 'boolean'],
                'custom_light_tokens' => ['nullable', 'array'],
                'custom_dark_tokens' => ['nullable', 'array'],
                'mobile_app_font_id' => ['nullable', 'integer', Rule::exists('mobile_app_fonts', 'id')->where('status', true)],
                'dark_mode' => ['nullable', 'string', Rule::in(config('mobile_appearance.dark_mode_values', []))],
            ],
            MobileColorTokens::userOverrideValidationRules('custom_light_tokens'),
            MobileColorTokens::userOverrideValidationRules('custom_dark_tokens'),
        );
    }
}
