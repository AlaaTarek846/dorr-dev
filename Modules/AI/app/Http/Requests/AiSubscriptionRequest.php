<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiSubscriptionRequest extends FormRequest
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
        $isUpdate = $this->route()->getActionMethod() === 'update';

        return [
            'owner_type' => [$isUpdate ? 'sometimes' : 'required', Rule::in(['user', 'provider'])],
            'owner_id' => [$isUpdate ? 'sometimes' : 'required', 'integer'],
            'plan_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:ai_plans,id'],
            'starts_at' => [$isUpdate ? 'sometimes' : 'required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'expired', 'cancelled', 'suspended'])],
        ];
    }
}
