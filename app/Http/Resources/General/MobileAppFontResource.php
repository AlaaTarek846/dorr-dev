<?php

namespace App\Http\Resources\General;

use App\Models\MobileAppFont;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileAppFontResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MobileAppFont $font */
        $font = $this->resource;

        return [
            'id' => $font->id,
            'name' => $font->name,
            'slug' => $font->slug,
            'status' => (bool) $font->status,
            'is_default' => (bool) $font->is_default,
            'sort_order' => $font->sort_order,
            'font_files' => $font->getMedia(MobileAppFont::FONT_FILES_COLLECTION)
                ->sortBy('order_column')
                ->values()
                ->map(fn ($media) => [
                    'id' => $media->id,
                    'file_name' => $media->file_name,
                    'url' => self::pathOnlyUrl($media->getUrl()),
                    'weight' => $media->getCustomProperty('weight', '400'),
                ])
                ->all(),
            'created_at' => $font->created_at?->toISOString(),
            'updated_at' => $font->updated_at?->toISOString(),
            'deleted_at' => $font->deleted_at?->toISOString(),
        ];
    }

    private static function pathOnlyUrl(string $url): string
    {
        if ($url === '' || str_starts_with($url, '/')) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $url;
    }
}
