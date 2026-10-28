<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Services\AiOutputGalleryService;

/** Search in the history (`q`, spec 134) and the outputs gallery filter (`kind`, spec 344). */
class AiHistorySearchRequest extends FormRequest
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
        return $this->route()->getActionMethod() === 'outputs'
            ? ['kind' => ['nullable', Rule::in(AiOutputGalleryService::KINDS)]]
            : ['q' => ['required', 'string', 'min:2', 'max:100']];
    }
}
