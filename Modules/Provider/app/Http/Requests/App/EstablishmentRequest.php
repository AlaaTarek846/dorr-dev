<?php

namespace Modules\Provider\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Provider\Enums\EstablishmentType;

/** The basic data after sign-in (A-03), or an edit of it. Creating also needs the service and the main branch's place. */
class EstablishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $req = $creating ? 'required' : 'sometimes';

        return [
            'type' => [$req, Rule::enum(EstablishmentType::class)],
            'name' => [$req, 'string', 'max:150'],
            'country_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('countries', 'id')->where('status', true)],
            'commercial_register_no' => ['nullable', 'string', 'max:64', Rule::requiredIf(fn () => $this->input('type') === EstablishmentType::Company->value)],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'service_ids' => [$req, 'array', 'min:1'],
            'service_ids.*' => ['integer', 'distinct'],
            'branch' => [$creating ? 'required' : 'prohibited', 'array'],
            'branch.name' => ['nullable', 'string', 'max:150'],
            'branch.lat' => [$creating ? 'required' : 'prohibited', 'numeric', 'between:-90,90'],
            'branch.lng' => [$creating ? 'required' : 'prohibited', 'numeric', 'between:-180,180'],
            'branch.radius_km' => ['nullable', 'numeric', 'min:0.5', 'max:500'],
            'branch.address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
