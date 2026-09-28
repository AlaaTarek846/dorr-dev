<?php

namespace Modules\Sms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sms\Services\Sms\SmsAdapterRegistry;

class SmsProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $service = SmsAdapterRegistry::instance();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'key' => $this->key,
            'label' => $service->label($this->key),
            'is_active' => (bool) $this->is_active,
            'is_available' => (bool) $this->is_available,
            'test_status' => $this->test_status ?? 'never_tested',
            'test_error' => $this->test_error,
            'last_tested_at' => $this->last_tested_at?->toDateTimeString(),
            'capabilities' => $service->capabilities($this->key),
            // Configuration SCHEMA + non-secret values only. Secret VALUES are
            // never exposed, only whether they are set (is_set).
            'configuration_meta' => $this->configurationMeta(
                $service->configurationSchema($this->key),
                $this->configuration_plaintext,
            ),
            'accounts_count' => $this->whenCounted('smsAccounts'),
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
