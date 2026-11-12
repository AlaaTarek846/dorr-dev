<?php

namespace Modules\Provider\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class VehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'vehicle_type_id' => [$req, 'integer'],
            'branch_id' => ['sometimes', 'nullable', 'integer'],
            // The plate's letters are as much the plate as its numbers (the admin matches both to the photo).
            'plate_letters' => [$req, 'string', 'max:32'],
            'plate_number' => [$req, 'string', 'max:20'],
            'make' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:40'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
        ];
    }
}
