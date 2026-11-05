<?php

namespace Modules\Provider\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\User\Http\Requests\ValidatesCountryPhone;

/** Inviting someone by phone (with their permissions and branches), or changing what a member may do. */
class MemberRequest extends FormRequest
{
    use ValidatesCountryPhone;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $inviting = $this->isMethod('post');

        return [
            'dial_code' => [$inviting ? 'required' : 'prohibited', 'string', 'max:5'],
            'phone' => [$inviting ? 'required' : 'prohibited', 'string', 'max:15'],
            'permissions' => [$inviting ? 'present' : 'sometimes', 'array'],
            'permissions.*' => ['string', 'max:64'],
            'branch_ids' => ['sometimes', 'nullable', 'array'],
            'branch_ids.*' => ['integer', 'distinct'],
            'is_exclusive' => ['sometimes', 'boolean'],
            'offer_limit_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        if (! $this->isMethod('post')) {
            return;
        }
        $validator->after(function (Validator $validator): void {
            $this->validateCountryPhone($validator, (string) $this->input('dial_code'), (string) $this->input('phone'));
        });
    }

    public function fullPhone(): string
    {
        return '+'.ltrim((string) $this->validated('dial_code'), '+').$this->validated('phone');
    }
}
