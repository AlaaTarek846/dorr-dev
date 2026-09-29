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
            'language_id' => $this->language_id,
            'language' => $this->whenLoaded('language', fn () => [
                'id' => $this->language?->id,
                'code' => $this->language?->code,
                'direction' => $this->language?->direction,
            ]),
            'category' => $this->category,
            'meta_template_id' => $this->meta_template_id,
            'meta_language' => $this->meta_language,
            'meta_status' => $this->meta_status,
            'components' => $this->components,
            'body' => $this->body,
            'is_active' => (bool) $this->is_active,
            'is_approved' => $this->meta_status === 'approved',
            'last_synced_at' => $this->last_synced_at?->toDateTimeString(),
            'last_sync_error' => $this->last_sync_error,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
