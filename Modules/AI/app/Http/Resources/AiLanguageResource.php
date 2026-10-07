<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Root-cause fix (languages consolidation): serializes the platform's
 * general Language model (not a separate "ai_languages" row) for the AI
 * admin screen - "code"/"direction" come straight from the shared
 * languages table, "name" is the real per-locale translation, and
 * "ai_enabled" is the one AI-specific flag this screen can actually
 * change (creating/renaming/deleting a language belongs to the general
 * Languages admin screen, not here, since doing it from here would
 * silently affect the website/dashboard too).
 */
class AiLanguageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->translatedName(),
            'direction' => $this->direction instanceof \App\Enums\TextDirection ? $this->direction->value : $this->direction,
            'ai_enabled' => (bool) $this->ai_enabled,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
