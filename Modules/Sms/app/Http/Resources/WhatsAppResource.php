<?php

namespace Modules\Sms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => (bool) $this->is_active,
            'is_available' => (bool) $this->is_available,
            'last_tested_at' => $this->last_tested_at?->toDateTimeString(),
            'test_status' => $this->test_status ?? 'never_tested',
            'test_error' => $this->test_error,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            // Sensitive credentials are NEVER exposed.
            'configuration_meta' => [
                'fields' => \Modules\Sms\Services\Sms\MetaWhatsAppAdapter::requiredCredentials(),
            ],
        ];
    }
}
