<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A message in a support ticket: text, a photo, or both. Used by the app and by the dashboard. */
class StoreSupportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user('user_api') ?? $this->user('admin_api'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:4000', 'required_without:image'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
        ];
    }
}
