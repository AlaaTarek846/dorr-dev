<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTranslations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlagResource extends JsonResource
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
            'status' => (bool) $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ], $this->translationFields());
    }
}
