<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Models\AiKnowledgeSource;

class AiKnowledgeSourceIngestRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'min:20'],
            'domain' => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'publisher' => ['nullable', 'string', 'max:255'],
            'authority' => ['nullable', 'string', 'max:255'],
            'published_at' => ['nullable', 'date'],
            'effective_at' => ['nullable', 'date'],
            'data_classification' => ['nullable', Rule::in([
                AiKnowledgeSource::CLASSIFICATION_PUBLIC,
                AiKnowledgeSource::CLASSIFICATION_INTERNAL,
                AiKnowledgeSource::CLASSIFICATION_CONFIDENTIAL,
                AiKnowledgeSource::CLASSIFICATION_PERSONAL,
                AiKnowledgeSource::CLASSIFICATION_SECRET,
                AiKnowledgeSource::CLASSIFICATION_UNVERIFIED,
            ])],
        ];
    }
}
