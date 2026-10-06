<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'is_error' => (bool) $this->is_error,
            // DORR AI safety (spec 350–362): the class and the approved texts, for the alert card.
            'safety' => $this->safety,
            'model' => $this->model,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
