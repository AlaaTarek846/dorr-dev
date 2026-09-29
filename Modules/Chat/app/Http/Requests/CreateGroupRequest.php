<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateGroupRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'avatar' => ['nullable', 'image', 'max:10240'],
            // Registered accounts only; people whose privacy forbids it come back in `not_added`.
            'members' => ['required', 'array', 'min:1', 'max:1024'],
            'members.*' => ['integer', 'distinct'],
            'disappearing_seconds' => ['nullable', Rule::in([86400, 604800, 7776000])],
        ];
    }
}
