<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One AI language variant - the same keys as before (the user app's language settings read them too). `name` is in
 * the request's language (X-Locale / locale middleware); `is_active` is kept next to the catalog's `status`. Only the
 * admin panel gets `translations[]` (every language, for the edit form's language tabs).
 */
class AiLanguageVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Root-cause fix (languages consolidation): "language" is now
            // App\Models\Language, which has no plain "name" column -
            // display names are per-locale, via translatedName().
            'language' => $this->whenLoaded('language', fn () => $this->language ? [
                'id' => $this->language->id,
                'code' => $this->language->code,
                'name' => $this->language->translatedName(),
            ] : null),
            'language_id' => $this->language_id,
            'code' => $this->code,
            'name' => $this->name,
            'style' => $this->style,
            'is_default' => (bool) $this->is_default,
            'status' => (bool) $this->status,
            'is_active' => (bool) $this->status,
            'translations' => $this->when(
                $request->is('api/admin/*') && $this->relationLoaded('translations'),
                fn () => $this->translations->map(fn ($item) => [
                    'locale' => $item->locale,
                    'name' => $item->name,
                ])->values(),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
