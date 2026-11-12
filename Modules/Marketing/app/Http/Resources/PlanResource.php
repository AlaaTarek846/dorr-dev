<?php

namespace Modules\Marketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Marketing\Models\MarketingApproval;
use Modules\Marketing\Models\MarketingPlan;

/** @mixin MarketingPlan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $version = $this->relationLoaded('currentVersion') ? $this->currentVersion : null;
        $approval = MarketingApproval::query()->where('subject_type', 'plan')->where('subject_id', $this->id)
            ->where('status', MarketingApproval::ACTIVE)->latest('id')->first();

        return [
            'id' => $this->uuid,
            'type' => $this->plan_type,
            'status' => $this->status->value,
            'versions_count' => $this->versions_count ?? null,
            'current_version' => $version === null ? null : (new PlanVersionResource($version))->toArray($request),
            'approval' => $approval === null ? null : [
                'id' => $approval->uuid,
                'version' => $approval->subject_version,
                'payload_hash' => $approval->payload_hash,
                'approved_at' => $approval->approved_at?->toIso8601String(),
            ],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
