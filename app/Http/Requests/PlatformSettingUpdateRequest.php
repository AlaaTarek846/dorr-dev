<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlatformSettingUpdateRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    public const MEDIA_COLLECTIONS = [
        'logo',
        'logo_dark',
        'favicon_ico',
        'favicon_16',
        'favicon_32',
        'apple_touch_icon',
        'web_manifest',
    ];

    public function authorize(): bool
    {
        return (bool) $this->user('admin_api');
    }

    protected function prepareForValidation(): void
    {
        $flags = [];

        foreach (self::MEDIA_COLLECTIONS as $collection) {
            $flags["remove_{$collection}"] = filter_var(
                $this->input("remove_{$collection}"),
                FILTER_VALIDATE_BOOLEAN,
            );
        }

        $this->merge($flags);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'app_name' => ['required', 'string', 'max:255'],
        ];

        foreach (self::MEDIA_COLLECTIONS as $collection) {
            $rules["remove_{$collection}"] = ['nullable', 'boolean'];
        }

        $rules['logo'] = ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/webp,image/svg+xml', 'max:4096'];
        $rules['logo_dark'] = ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/webp,image/svg+xml', 'max:4096'];
        $rules['favicon_ico'] = ['nullable', 'file', 'mimes:ico', 'max:512'];
        $rules['favicon_16'] = ['nullable', 'file', 'mimes:png,ico,jpg,jpeg,webp', 'max:1024'];
        $rules['favicon_32'] = ['nullable', 'file', 'mimes:png,ico,jpg,jpeg,webp', 'max:1024'];
        $rules['apple_touch_icon'] = ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'];
        $rules['web_manifest'] = ['nullable', 'file', 'mimes:json,webmanifest', 'max:2048'];

        return $rules;
    }
}
