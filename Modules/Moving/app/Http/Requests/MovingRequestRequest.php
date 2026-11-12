<?php

namespace Modules\Moving\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Moving\Enums\LoadType;

/** A move draft, or an edit of it. Everything is optional here; publishing checks what a request must have (DEC-037). */
class MovingRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $end = fn (string $e) => [
            "{$e}_lat" => ['nullable', 'numeric', 'between:-90,90'],
            "{$e}_lng" => ['nullable', 'numeric', 'between:-180,180'],
            "{$e}_address" => ['nullable', 'string', 'max:255'],
            "{$e}_area" => ['nullable', 'string', 'max:120'],
            "{$e}_floor" => ['nullable', 'integer', 'between:-5,200'],
            "{$e}_elevator" => ['sometimes', 'boolean'],
            "{$e}_entrance" => ['nullable', 'string', 'max:255'],
        ];

        return [
            'load_type' => [$this->isMethod('post') ? 'required' : 'sometimes', Rule::enum(LoadType::class)],
            'description' => ['nullable', 'string', 'max:3000'],
            'pieces_count' => ['nullable', 'integer', 'between:1,10000'],
            'dimensions' => ['nullable', 'string', 'max:255'],
            'vehicle_type_id' => ['nullable', 'integer', Rule::exists('provider_vehicle_types', 'id')->where('status', true)],
            // Every vehicle type the customer accepts (a quarter or a half truck…), the first choice first.
            'vehicle_type_ids' => ['sometimes', 'nullable', 'array', 'max:10'],
            'vehicle_type_ids.*' => ['integer', 'distinct', Rule::exists('provider_vehicle_types', 'id')->where('status', true)],
            'workers_count' => ['sometimes', 'integer', 'between:0,50'],
            'packing' => ['sometimes', 'boolean'],
            'disassembly' => ['sometimes', 'boolean'],
            'crane' => ['sometimes', 'boolean'],
            'technician' => ['sometimes', 'boolean'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$end('pickup'),
            ...$end('dropoff'),
        ];
    }
}
