<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiTrialControlUpdateRequest extends FormRequest
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
            'trial_status' => ['nullable', Rule::in(['eligible', 'active', 'ended'])],
            'abuse_status' => ['nullable', Rule::in(['clear', 'flagged', 'blocked'])],
            'abuse_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
