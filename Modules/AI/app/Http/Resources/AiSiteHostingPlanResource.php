<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AI\Models\AiSiteHostingPlan;

/** @mixin AiSiteHostingPlan */
class AiSiteHostingPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $admin = str_starts_with((string) $request->path(), 'admin/');
        $price = $admin ? null : $this->priceFor(currentCountry());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'period' => $this->period,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            $this->mergeWhen(! $admin, [
                'price' => $price['price'] ?? null,
                'currency' => $price['currency'] ?? null,
                'available' => $price !== null,
            ]),
            $this->mergeWhen($admin, fn () => ['prices_count' => $this->prices_count ?? $this->prices()->count()]),
        ];
    }
}
