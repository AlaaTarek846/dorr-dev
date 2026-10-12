<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AI\Models\AiSiteServiceRequest;
use Modules\AI\Services\Sites\AiSiteServiceRequestService;

/** List row of a service request (customer or admin view, decided by the path). @mixin AiSiteServiceRequest */
class AiSiteServiceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->is('api/admin/*', 'admin/*');

        return app(AiSiteServiceRequestService::class)->present($this->resource, detailed: false, forAdmin: $admin) + ($admin ? [
            'owner' => ['type' => $this->owner_type, 'id' => $this->owner_id, 'name' => $this->owner?->name ?? $this->owner?->full_name ?? null],
        ] : []);
    }
}
