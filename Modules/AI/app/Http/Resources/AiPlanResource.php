<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One AI plan. name / description / badge / features are in the request's language (X-Locale / locale
 * middleware) - the same keys the apps have always read (AiPlanDto on Android/iOS, the driver app), so they need no
 * change. `is_active` is kept next to the catalog's `status` for them. Only the admin panel gets `translations[]`
 * (every language, for the edit form's language tabs).
 */
class AiPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'usage_minutes' => $this->usage_minutes,
            'cooldown_minutes' => $this->cooldown_minutes,
            'image_daily_limit' => $this->image_daily_limit,
            'video_daily_limit' => $this->video_daily_limit,
            'video_max_seconds' => $this->video_max_seconds,
            'site_projects_limit' => $this->site_projects_limit,
            'site_daily_generations' => $this->site_daily_generations,
            'duration_days' => $this->duration_days,
            'price' => $this->price,
            'original_price' => $this->original_price,
            'discount_percent' => $this->discountPercent(),
            'currency' => $this->currency,
            'currency_id' => $this->currency_id,
            'badge' => $this->badge,
            'is_featured' => (bool) $this->is_featured,
            'features' => $this->features,
            'is_trial' => (bool) $this->is_trial,
            'status' => (bool) $this->status,
            'is_active' => (bool) $this->status,
            'sort_order' => $this->sort_order,
            'translations' => $this->when(
                $request->is('api/admin/*') && $this->relationLoaded('translations'),
                fn () => $this->translations->map(fn ($item) => [
                    'locale' => $item->locale,
                    'name' => $item->name,
                    'description' => $item->description,
                    'badge' => $item->badge,
                    'features' => array_values($item->features ?? []),
                ])->values(),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
