<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiSubscribeRequest extends FormRequest
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
            'plan_id' => ['required', 'integer', 'exists:ai_plans,id'],
            // Default true (auto-renewal on by default) is applied in the
            // controller, not here - a FormRequest rule can only validate
            // a present value, not supply a default for an absent key.
            'auto_renew' => ['nullable', 'boolean'],
        ];
    }
}
