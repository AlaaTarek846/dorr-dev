<?php

namespace Modules\Sms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Send Test SMS payload: account + country + recipient + message.
 *
 * The country drives E.164 normalization of the recipient number
 * (see PhoneNumberNormalizer), so it is required alongside the number.
 */
class SmsSendTestRequest extends FormRequest
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
            'account_id' => ['nullable', 'integer', 'exists:sms_accounts,id'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'to' => ['required', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:1600'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country_id.required' => __('sms.accounts.country_required'),
            'country_id.exists' => __('sms.accounts.country_not_found'),
            'to.required' => __('sms.accounts.test_number_required'),
        ];
    }
}
