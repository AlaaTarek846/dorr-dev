<?php

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Http\Requests\Concerns\TranslatesSiteAttributes;
use Modules\AI\Services\Sites\AiSiteTokenizer;

/** The one-shot "tell us everything about your site" brief. */
class AiSiteProjectStoreRequest extends FormRequest
{
    use TranslatesSiteAttributes;

    public const SITE_TYPES = ['portfolio', 'business', 'store_showcase', 'restaurant', 'personal', 'landing', 'other'];

    public const SECTIONS = ['hero', 'about', 'services', 'portfolio', 'gallery', 'testimonials', 'pricing', 'faq', 'team', 'contact', 'map'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $color = ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $url = ['nullable', 'url:http,https', 'max:500'];

        $rules = [
            'offer_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'min:2', 'max:150'],
            'business_name' => ['required', 'string', 'min:2', 'max:150'],
            'activity' => ['required', 'string', 'min:2', 'max:250'],
            'site_type' => ['required', Rule::in(self::SITE_TYPES)],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
            'tagline' => ['nullable', 'string', 'max:200'],
            'languages' => ['nullable', 'array', 'min:1', 'max:2'],
            'languages.*' => ['string', Rule::in(['ar', 'en'])],
            'services' => ['nullable', 'array', 'max:12'],
            'services.*.name' => ['required', 'string', 'max:120'],
            'services.*.description' => ['nullable', 'string', 'max:500'],
            'services.*.price' => ['nullable', 'string', 'max:60'],
            'contact' => ['nullable', 'array'],
            'contact.phone' => ['nullable', 'string', 'max:40'],
            'contact.whatsapp' => ['nullable', 'string', 'max:40'],
            'contact.email' => ['nullable', 'email', 'max:150'],
            'contact.address' => ['nullable', 'string', 'max:300'],
            'contact.map_url' => $url,
            'contact.working_hours' => ['nullable', 'string', 'max:300'],
            'colors' => ['nullable', 'array'],
            'colors.primary' => $color,
            'colors.secondary' => $color,
            'colors.accent' => $color,
            'style' => ['nullable', 'string', 'max:200'],
            'tone' => ['nullable', 'string', 'max:200'],
            'sections' => ['nullable', 'array', 'max:12'],
            'sections.*' => ['string', Rule::in(self::SECTIONS)],
            'extra_notes' => ['nullable', 'string', 'max:3000'],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
            'images' => ['nullable', 'array', 'max:8'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:3072'],
        ];

        foreach (AiSiteTokenizer::SOCIAL as $network) {
            $rules['social.'.$network] = $url;
        }

        return $rules + ['social' => ['nullable', 'array']];
    }

    /** The brief as stored: validated fields only, empty values dropped, assets (files) excluded. */
    public function brief(): array
    {
        $data = $this->validated();

        unset($data['offer_id'], $data['logo'], $data['images']);

        $data['languages'] = array_values($data['languages'] ?? ['ar']);

        return $this->prune($data);
    }

    protected function prune(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->prune($value);
            }

            if ($data[$key] === null || $data[$key] === '' || $data[$key] === []) {
                unset($data[$key]);
            }
        }

        return array_is_list($data) ? array_values($data) : $data;
    }
}
