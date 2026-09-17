<?php

namespace App\Http\Resources\General;

use App\Enums\Status;
use App\Http\Resources\Concerns\FormatsTranslations;
use App\Models\DashboardTheme;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardThemeResource extends JsonResource
{
    use FormatsTranslations;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DashboardTheme $theme */
        $theme = $this->resource;

        return array_merge([
            'id' => $theme->id,
            'slug' => $theme->slug,
            'path' => $theme->path,
            'status' => $theme->status === Status::Active,
            'is_default' => (bool) $theme->is_default,
            'sort_order' => $theme->sort_order,
            'preview_image' => $theme->getSingleMediaUrl(DashboardTheme::PREVIEW_IMAGE_COLLECTION) ?: null,
            'preview_image_thumb' => $theme->getSingleMediaThumbUrl(DashboardTheme::PREVIEW_IMAGE_COLLECTION) ?: null,
            'created_at' => $theme->created_at?->toISOString(),
            'updated_at' => $theme->updated_at?->toISOString(),
        ], $this->translationFields());
    }
}
