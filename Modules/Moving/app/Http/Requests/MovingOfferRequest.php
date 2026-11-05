<?php

namespace Modules\Moving\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** An offer (REQ-021): the price, what it includes, the arrival time and how long it holds are needed (DEC-037). */
class MovingOfferRequest extends FormRequest
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
            'vehicle_uuid' => [$req, 'uuid'],
            'price_minor' => [$req, 'integer', 'min:1'],
            'workers_count' => ['sometimes', 'integer', 'between:0,50'],
            'trips_count' => ['sometimes', 'integer', 'between:1,50'],
            'includes' => [$this->isMethod('post') ? 'present' : 'sometimes', 'array'],
            'includes.*' => ['boolean'],
            'technician_note' => ['nullable', 'string', 'max:255'],
            'arrival_at' => [$req, 'date', 'after:now'],
            'terms' => ['nullable', 'string', 'max:3000'],
            'after_service' => ['nullable', 'string', 'max:2000'],
            'valid_until' => [$req, 'date', 'after:now'],
        ];
    }
}
