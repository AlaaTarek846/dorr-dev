<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiPlanPriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->plan_id,
            'country_id' => $this->country_id,
            'country_code' => $this->country?->code,
            'country_name' => $this->country?->translatedName() ?? $this->country?->code,
            'currency_id' => $this->currency_id,
            'currency_code' => $this->currency?->code,
            'currency_symbol' => $this->currency?->symbol,
            'price' => $this->price,
            'original_price' => $this->original_price,
            'discount_percent' => $this->discountPercent(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
