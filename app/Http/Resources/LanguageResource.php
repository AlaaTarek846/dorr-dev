<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTranslations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LanguageResource extends JsonResource
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
            'direction' => $this->direction,
            'is_default_website' => (bool) $this->is_default_website,
            'is_default_dashboard' => (bool) $this->is_default_dashboard,
            'stores_translation' => (bool) $this->stores_translation,
            'status' => (bool) $this->status,
            'flag_id' => $this->flag_id,
            'flag' => $this->whenLoaded('flag', fn () => [
                'id' => $this->flag?->id,
                'code' => $this->flag?->code,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ], $this->translationFields());
    }
}
