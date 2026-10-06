<?php

namespace App\Http\Requests\General;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Dashboard requests for ratings: only bulk delete carries input (an admin never creates or edits a rating).
 */
class RatingRequest extends FormRequest
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
        return match ($this->route()->getActionMethod()) {
            'deleteMultiple' => [
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer', 'distinct', 'exists:ratings,id'],
            ],
            default => [],
        };
    }
}
