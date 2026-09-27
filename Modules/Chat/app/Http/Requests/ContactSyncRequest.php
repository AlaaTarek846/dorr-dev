<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A batch of the phone's address book. Big books are sent in several batches; the last one
 * says `full: true` only when the app sends the *whole* book in one go.
 */
class ContactSyncRequest extends FormRequest
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
            'contacts' => ['present', 'array', 'max:2000'],
            'contacts.*.name' => ['nullable', 'string', 'max:150'],
            'contacts.*.phone' => ['required', 'string', 'max:40'],
            'full' => ['sometimes', 'boolean'],
        ];
    }
}
