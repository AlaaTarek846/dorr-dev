<?php

namespace App\Http\Resources\General;

use App\Http\Resources\Concerns\FormatsTranslations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrencyResource extends JsonResource
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
            'symbol' => $this->symbol,
            'decimal_places' => $this->decimal_places,
            'exchange_rate' => $this->exchange_rate,
            'is_default' => (bool) $this->is_default,
            'status' => (bool) $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ], $this->translationFields());
    }
}
