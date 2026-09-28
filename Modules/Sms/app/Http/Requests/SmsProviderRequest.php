<?php

namespace Modules\Sms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sms\Services\Sms\SmsAdapterRegistry;

class SmsProviderRequest extends FormRequest
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
        $provider = $this->route('sms_provider');

        return [
            'name' => ['required', 'string', 'max:150'],
            'key' => [
                'required',
                'string',
                'max:80',
                Rule::in(app(SmsAdapterRegistry::class)->keys()),
                Rule::unique('sms_providers', 'key')->ignore($provider),
            ],
            'is_active' => ['nullable', 'boolean'],
            'is_available' => ['nullable', 'boolean'],
            'configuration' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('sms.providers.name_required'),
            'key.required' => __('sms.providers.key_required'),
            'key.in' => __('sms.providers.unknown_provider'),
            'key.unique' => __('sms.providers.already_registered'),
        ];
    }
}
