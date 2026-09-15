<?php

namespace Modules\User\Http\Requests;

use App\Enums\Gender;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UserRequest extends FormRequest
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
        $userId = $this->route('user');

        return match ($this->route()->getActionMethod()) {
            'store' => $this->storeRules($userId),
            'update' => $this->updateRules($userId),
            'changeStatus' => [
                'status' => ['required', new Enum(UserStatus::class)],
            ],
            'deleteMultiple' => [
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function storeRules(mixed $userId): array
    {
        return array_merge($this->baseRules($userId), [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function updateRules(mixed $userId): array
    {
        return array_merge($this->baseRules($userId), [
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(mixed $userId): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'gender' => ['nullable', new Enum(Gender::class)],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'status' => ['nullable', new Enum(UserStatus::class)],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ];
    }
}
