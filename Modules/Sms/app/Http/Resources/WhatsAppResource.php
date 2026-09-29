<?php

namespace Modules\Sms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sms\Services\Sms\Adapters\MetaWhatsAppAdapter;

class WhatsAppResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (! $this->resource) {
            return [];
        }

        $this->loadMissing(['countries', 'phoneCountry']);

        return [
            'id' => $this->id,
            'name' => $this->name,
            // The business phone number entered by the admin (E.164). It is
            // distinct from `phone_number_id`, which is the Meta identifier.
            'phone_number' => $this->phone_number,
            'phone_country_id' => $this->phone_country_id,
            'phone_country' => $this->whenLoaded('phoneCountry', fn () => [
                'id' => $this->phoneCountry?->id,
                'code' => $this->phoneCountry?->code,
                'dial_code' => $this->phoneCountry?->dial_code,
            ]),
            // Meta identifiers. These are public account references, not
            // credentials, so the admin needs to see and be able to edit them.
            'phone_number_id' => $this->phone_number_id,
            'business_account_id' => $this->business_account_id,
            'api_version' => $this->api_version,
            'is_active' => (bool) $this->is_active,
            'is_available' => (bool) $this->is_available,
            'is_ready' => $this->isReadyForTemplates(),
            'last_tested_at' => $this->last_tested_at?->toDateTimeString(),
            'test_status' => $this->testStatus(),
            'test_error' => $this->test_error,
            'countries' => $this->countries->pluck('country_id')->values(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            // The access token is the only secret, so it is never returned.
            // A boolean lets the UI show that one is already stored.
            'has_access_token' => filled($this->access_token),
            'configuration_meta' => [
                'fields' => MetaWhatsAppAdapter::requiredCredentials(),
            ],
        ];
    }
}
