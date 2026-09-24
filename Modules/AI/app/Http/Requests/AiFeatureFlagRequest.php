<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Models\AiFeatureFlag;

class AiFeatureFlagRequest extends FormRequest
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
            'key' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:150'],
            'target_type' => [$isUpdate ? 'sometimes' : 'required', Rule::in([
                AiFeatureFlag::TARGET_PROVIDER,
                AiFeatureFlag::TARGET_MODEL,
                AiFeatureFlag::TARGET_TOOL,
            ])],
            'provider_id' => ['nullable', 'integer', 'exists:ai_providers,id'],
            'model_key' => ['nullable', 'string', 'max:150'],
            'tool_key' => ['nullable', 'string', 'max:150'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'domain' => ['nullable', 'string', 'max:100'],
            'environment' => ['nullable', 'string', 'max:20'],
            'is_enabled' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
