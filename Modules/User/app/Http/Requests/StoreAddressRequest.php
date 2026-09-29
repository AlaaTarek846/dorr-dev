<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('user_api');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['home', 'work', 'other'])],
            'title' => ['nullable', 'string', 'max:100'],
            'building_number' => ['nullable', 'string', 'max:50'],
            'floor' => ['nullable', 'string', 'max:50'],
            'address_details' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => __('validation.attributes.address_type'),
            'title' => __('validation.attributes.address_title'),
            'building_number' => __('validation.attributes.building_number'),
            'floor' => __('validation.attributes.floor'),
            'address_details' => __('validation.attributes.address_details'),
            'landmark' => __('validation.attributes.landmark'),
            'latitude' => __('validation.attributes.latitude'),
            'longitude' => __('validation.attributes.longitude'),
            'is_default' => __('validation.attributes.is_default'),
        ];
    }
}
