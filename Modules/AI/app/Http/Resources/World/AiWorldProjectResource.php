<?php

namespace Modules\AI\Http\Resources\World;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiWorldProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'mode' => $this->mode,
            'status' => $this->status,
            'quality' => $this->quality,
            'total_seconds' => $this->total_seconds,
            'estimated_minor' => $this->estimated_minor,
            'charged_minor' => $this->charged_minor,
            'refunded_minor' => $this->refunded_minor,
            'currency' => $this->currency,
            'ai_generated' => true,
            'identity_profile_id' => $this->identity_profile_id,
            'has_final_video' => $this->final_video_path !== null,
            'error_code' => $this->error_code,
            'created_at' => $this->created_at?->toIso8601String(),
            'scenes' => $this->whenLoaded('scenes', fn () => $this->scenes->map(fn ($s) => [
                'id' => $s->id,
                'position' => $s->position,
                'location_id' => $s->location_id,
                'prompt' => $s->prompt,
                'seconds' => $s->seconds,
                'status' => $s->status,
                'attempts' => $s->attempts,
                'price_minor' => $s->price_minor,
                'refunded_minor' => $s->refunded_minor,
                'error_code' => $s->error_code,
                'identity_score' => $s->currentVersion?->identity_score,
                'identity_low' => $s->currentVersion?->identity_score !== null && $s->currentVersion->identity_score < (float) \Modules\AI\Models\World\AiWorldSetting::current()->identity_threshold,
            ])->all()),
        ];
    }
}
