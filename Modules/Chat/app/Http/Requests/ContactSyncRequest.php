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
            // Loose on purpose: one odd address-book entry must not reject the whole batch —
            // names are trimmed to 150 and unusable numbers are skipped by ContactService.
            'contacts.*.name' => ['nullable', 'string', 'max:500'],
            'contacts.*.phone' => ['required', 'string', 'max:64'],
            'full' => ['sometimes', 'boolean'],
        ];
    }
}
