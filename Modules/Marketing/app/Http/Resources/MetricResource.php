<?php

namespace Modules\Marketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MetricResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->metric_date?->toDateString(),
            'channel' => $this->channel,
            'source' => $this->source,
            'basis' => $this->basis,
            'content_id' => $this->contentItem?->uuid,
            'impressions' => $this->impressions,
            'clicks' => $this->clicks,
            'spend_minor' => $this->spend_minor,
            'conversions' => $this->conversions,
            'revenue_minor' => $this->revenue_minor,
            'currency' => $this->currency,
            'note' => $this->note,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
