<?php

namespace Modules\Sms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SmsAccountRequest extends FormRequest
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
        return match ($this->route()->getActionMethod()) {
            'store' => $this->baseRules(providerRequired: true),
            'update' => $this->baseRules(providerRequired: false),
            default => [],
        };
    }

    /**
     * `provider_id` is required when creating an account because that is what
     * binds it to an adapter. On update it is optional: an edit usually only
     * changes the name or the credentials, and forcing a provider would make
     * every credential edit resend a field it has no use for.
     *
     * @return array<string, mixed>
     */
    protected function baseRules(bool $providerRequired): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'provider_id' => array_merge(
                [$providerRequired ? 'required' : 'sometimes', 'integer'],
                [Rule::exists('sms_providers', 'id')],
            ),
            'sender' => ['nullable', 'string', 'max:190'],
            'sender_code' => ['nullable', 'string', 'max:10'],
            'sender_type' => ['nullable', 'string', Rule::in(['number', 'alphanumeric'])],
            'configuration' => ['nullable', 'array'],
            'configuration.*' => ['nullable'],
            'purpose' => ['nullable', 'string', 'max:190'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('sms.accounts.name_required'),
            'provider_id.required' => __('sms.accounts.provider_required'),
            'provider_id.exists' => __('sms.accounts.provider_not_found'),
            'sender_type.in' => __('sms.accounts.invalid_sender_type'),
        ];
    }
}
