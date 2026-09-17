<?php

namespace App\Http\Resources\General;

use App\Http\Resources\Concerns\FormatsTranslations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryResource extends JsonResource
{
    use FormatsTranslations;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge([
            'id' => $this->id,
            'code' => $this->code,
            'code_alpha3' => $this->code_alpha3,
            'dial_code' => $this->dial_code,
            'phone_starts_with' => $this->phone_starts_with,
            'phone_length' => $this->phone_length,
            'is_default' => (bool) $this->is_default,
            'status' => (bool) $this->status,
            'flag_id' => $this->flag_id,
            'currency_id' => $this->currency_id,
            'flag' => $this->whenLoaded('flag', fn () => [
                'id' => $this->flag?->id,
                'code' => $this->flag?->code,
            ]),
            'currency' => $this->whenLoaded('currency', fn () => [
                'id' => $this->currency?->id,
                'code' => $this->currency?->code,
                'symbol' => $this->currency?->symbol,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ], $this->translationFields());
    }
}
