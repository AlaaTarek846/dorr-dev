<?php

namespace Modules\Marketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Marketing\Models\MarketingApproval;

class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $approval = MarketingApproval::query()->where('subject_type', 'campaign')->where('subject_id', $this->id)->where('status', MarketingApproval::ACTIVE)->latest('id')->first();
        $last = $this->actions()->latest('id')->first();

        return [
            'id' => $this->uuid,
            'provider' => $this->provider,
            'integration_id' => $this->integration?->uuid,
            'content_id' => $this->contentItem?->uuid,
            'name' => $this->name,
            'objective' => $this->objective,
            'status' => $this->status,
            'version' => $this->version,
            'daily_budget_minor' => $this->daily_budget_minor,
            'total_budget_minor' => $this->total_budget_minor,
            'currency' => $this->currency,
            'start_at' => $this->start_at?->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
            'targeting' => $this->targeting,
            'validation' => $this->validation ?? [],
            'approval' => $approval === null ? null : [
                'id' => $approval->uuid,
                'version' => $approval->subject_version,
                'cap_minor' => $approval->cap_minor,
                'expires_at' => $approval->expires_at?->toIso8601String(),
                'by_delegation' => $approval->delegation_id !== null,
            ],
            'platform_ref' => $this->platform_ref,
            'platform_status' => $this->platform_status,
            'last_error' => $this->last_error,
            'published_at' => $this->published_at?->toIso8601String(),
            'metrics_synced_at' => $this->metrics_synced_at?->toIso8601String(),
            'last_action' => $last === null ? null : ['id' => $last->uuid, 'type' => $last->action_type, 'status' => $last->status, 'error_code' => $last->error_code, 'at' => $last->created_at?->toIso8601String()],
        ];
    }
}
