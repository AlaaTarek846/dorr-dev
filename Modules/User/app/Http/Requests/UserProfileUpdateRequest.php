<?php

namespace Modules\User\Http\Requests;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UserProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('user_api');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => $this->input('phone') ?: null,
            'gender' => $this->input('gender') ?: null,
            'country_id' => $this->input('country_id') ?: null,
            'remove_avatar' => filter_var($this->input('remove_avatar'), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user('user_api')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => ['required', 'string', 'max:50'],
            'gender' => ['required', new Enum(Gender::class)],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
        ];
    }
}
