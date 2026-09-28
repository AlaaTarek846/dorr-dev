<?php

namespace Modules\Sms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sms\Services\Sms\SmsAdapterRegistry;
use Modules\Sms\Services\Sms\SmsAvailabilityService;

class SmsAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $providerService = SmsAdapterRegistry::instance();
        $providerKey = $this->provider?->key;
        $config = $this->configuration_plaintext;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider_id' => $this->provider_id,
            'provider_key' => $providerKey,
            'provider_label' => $providerKey ? $providerService->label($providerKey) : null,
            'provider' => new SmsProviderResource($this->whenLoaded('provider')),
            'sender' => $this->sender,
            'sender_code' => $this->sender_code,
            'sender_type' => $this->sender_type,
            'purpose' => $this->purpose,
            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,
            'test_status' => $this->test_status ?? 'never_tested',
            'last_tested_at' => $this->last_tested_at?->toDateTimeString(),
            'test_error' => $this->test_error,
            'is_usable' => SmsAvailabilityService::instance()->accountReady($this->resource),
            'provider_is_active' => (bool) ($this->provider?->is_active ?? false),
            'capabilities' => $providerKey ? $providerService->capabilities($providerKey) : [],
            'supports_balance' => $providerKey ? $providerService->supports($providerKey, 'balance') : false,
            'supports_sandbox' => $providerKey ? $providerService->supports($providerKey, 'test_mode') : false,
            // Schema + non-secret values only. Secret VALUES are never exposed,
            // only whether they are set (is_set).
            'configuration_meta' => $this->configurationMeta(
                $providerKey ? $providerService->configurationSchema($providerKey) : [],
                $config,
            ),
            'settings' => $this->settings,
            'created_at' => $this->created_at?->toDateString(),
            'updated_at' => $this->updated_at?->toDateString(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $schema
     * @param  array<string, mixed>  $config
     * @return array{fields: list<array<string, mixed>>}
     */
    protected function configurationMeta(array $schema, array $config): array
    {
        $fields = [];

        foreach ($schema as $field) {
            $isSecret = (bool) ($field['secret'] ?? false);

            $fields[] = [
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'required' => (bool) ($field['required'] ?? false),
                'secret' => $isSecret,
                'options' => $field['options'] ?? null,
                'value' => $isSecret ? null : ($config[$field['key']] ?? null),
                'has_value' => ! $isSecret && isset($config[$field['key']]),
                'is_set' => array_key_exists($field['key'], $config) && ! blank($config[$field['key']] ?? null),
            ];
        }

        return ['fields' => $fields];
    }
}
