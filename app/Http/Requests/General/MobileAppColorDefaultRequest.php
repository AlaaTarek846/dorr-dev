<?php

namespace App\Http\Requests\General;

use App\Support\Mobile\MobileColorTokens;
use Illuminate\Foundation\Http\FormRequest;

class MobileAppColorDefaultRequest extends FormRequest
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
        return array_merge(
            [
                'light_tokens' => ['required', 'array'],
                'dark_tokens' => ['required', 'array'],
            ],
            MobileColorTokens::colorTokenValidationRules('light_tokens'),
            MobileColorTokens::colorTokenValidationRules('dark_tokens'),
        );
    }
}
