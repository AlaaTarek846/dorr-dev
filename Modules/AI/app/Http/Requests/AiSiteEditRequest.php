<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\AI\Http\Requests\Concerns\TranslatesSiteAttributes;

class AiSiteEditRequest extends FormRequest
{
    use TranslatesSiteAttributes;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['instruction' => ['required', 'string', 'min:3', 'max:2000']];
    }
}
