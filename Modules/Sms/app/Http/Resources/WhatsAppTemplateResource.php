<?php

namespace Modules\Sms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_name' => $this->template_name,
            'language' => $this->language,
            'meta_status' => $this->meta_status,
            'last_synced_at' => $this->last_synced_at?->toDateTimeString(),
            'test_error' => $this->test_error,
            'is_active' => (bool) $this->is_active,
            'is_approved' => $this->meta_status === 'approved',
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
