<?php

namespace App\Http\Resources\General;

use App\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Rating */
class MobileRatingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stars' => (float) $this->stars,
            'comment' => $this->comment,
            'type' => $this->type,
            // 4–5 stars: the app may now show the store's in-app review. 1–3 stay internal feedback.
            'prompt_store_review' => $this->type === Rating::TYPE_REVIEW,
            'rateable_type' => Rating::aliasFor($this->rateable_type),
            'rateable_id' => $this->rateable_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
