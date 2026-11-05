<?php

namespace Modules\Provider\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A paper or a vehicle photo. Which fields are needed depends on its type, checked in DocumentService. */
class DocumentUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', Rule::in(['establishment', 'vehicle', 'driver'])],
            'subject_uuid' => ['required_unless:subject,establishment', 'nullable', 'uuid'],
            'document_type_id' => ['required', 'integer'],
            'front' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'number' => ['nullable', 'string', 'max:64'],
            'expires_on' => ['nullable', 'date', 'after:today'],
        ];
    }
}
