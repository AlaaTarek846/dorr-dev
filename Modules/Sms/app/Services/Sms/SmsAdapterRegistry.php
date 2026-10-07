<?php

namespace Modules\Sms\Services\Sms;

use Modules\Sms\Contracts\Sms\SmsProviderInterface;
use Modules\Sms\Services\Sms\Adapters\FourJawalySmsAdapter;
use Modules\Sms\Services\Sms\Adapters\SmsMisrSmsAdapter;
use Modules\Sms\Services\Sms\Adapters\TwilioSmsAdapter;

/**
 * SmsAdapterRegistry — the registry of every SMS provider adapter.
 *
 * Adding a brand-new provider means writing an adapter and registering it here —
 * no change to the controllers, models, or resources. Provider keys are NEVER
 * hard-coded in a controller; everything flows through this registry.
 */
class SmsAdapterRegistry
{
    /**
     * @var array<string, array{key: string, label: string, adapter: class-string}>
     */
    protected array $registry = [
        'twilio' => ['key' => 'twilio', 'label' => 'Twilio', 'adapter' => TwilioSmsAdapter::class],
        'sms_misr' => ['key' => 'sms_misr', 'label' => 'SMS Misr', 'adapter' => SmsMisrSmsAdapter::class],
        'four_jawaly' => ['key' => 'four_jawaly', 'label' => '4Jawaly', 'adapter' => FourJawalySmsAdapter::class],
    ];

    public static function instance(): self
    {
        return app(self::class);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->registry);
    }

    /**
     * @return array<string, array{key: string, label: string, adapter: class-string}>
     */
    public function registry(): array
    {
        return $this->registry;
    }

    /**
     * The adapter for a provider key, or null when the key is unknown.
     */
    public function adapter(?string $key): ?SmsProviderInterface
    {
        if (! $key || ! isset($this->registry[$key])) {
            return null;
        }

        $adapterClass = $this->registry[$key]['adapter'];

        return new $adapterClass;
    }

    public function label(?string $key): string
    {
        return $key && isset($this->registry[$key]) ? $this->registry[$key]['label'] : ($key ?: '—');
    }

    /**
     * Field schema (metadata only, never secrets) derived from the adapter.
     *
     * @return list<array<string, mixed>>
     */
    public function configurationSchema(?string $key): array
    {
        return $this->adapter($key)?->configurationSchema() ?? [];
    }

    /**
     * @return list<string>
     */
    public function capabilities(?string $key): array
    {
        return $this->adapter($key)?->capabilities() ?? [];
    }

    public function supports(?string $key, string $capability): bool
    {
        return in_array($capability, $this->capabilities($key), true);
    }

    /**
     * Normalise a submitted configuration against the declared schema.
     *
     * Returns a PLAINTEXT array — encryption is the model's job via its
     * `encrypted:array` cast. Encrypting here as well is what previously
     * double-encrypted the blob and made every read return a string.
     *
     * On edit, blank/absent secret values keep the stored value, so passing
     * $existingPlaintext lets the caller preserve secrets the form left empty.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>|null  $existingPlaintext
     * @return array<string, mixed>
     */
    public function prepareConfiguration(string $key, array $config, ?array $existingPlaintext = null): array
    {
        $result = [];

        foreach ($this->configurationSchema($key) as $field) {
            $fieldKey = $field['key'];
            $isSecret = (bool) $field['secret'];

            if ($isSecret && isset($config[$fieldKey]) && $config[$fieldKey] !== '') {
                $result[$fieldKey] = $config[$fieldKey];
            } elseif ($isSecret && isset($existingPlaintext[$fieldKey])) {
                $result[$fieldKey] = $existingPlaintext[$fieldKey];
            } elseif ($isSecret) {
                $result[$fieldKey] = null;
            } else {
                $result[$fieldKey] = $config[$fieldKey] ?? ($existingPlaintext[$fieldKey] ?? null);
            }
        }

        return $result;
    }

    /**
     * Validate a configuration against the declared schema.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: bool, 1: list<string>} [valid, requiredMissing]
     */
    public function validateConfiguration(string $key, array $config, bool $isUpdate = false): array
    {
        $missing = [];

        foreach ($this->configurationSchema($key) as $field) {
            if ($field['required'] && ! $isUpdate && ($config[$field['key']] ?? '') === '') {
                $missing[] = $field['key'];
            }
        }

        return [empty($missing), $missing];
    }

    /**
     * Run a real connection test through the adapter.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function testConnection(string $key, array $config): array
    {
        $adapter = $this->adapter($key);

        if (! $adapter) {
            return ['success' => false, 'message' => __('sms.providers.unsupported', ['key' => $key])];
        }

        return $adapter->testConnection($config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function getBalance(string $key, array $config): array
    {
        $adapter = $this->adapter($key);

        if (! $adapter) {
            return ['success' => false, 'message' => __('sms.providers.unsupported', ['key' => $key])];
        }

        return $adapter->getBalance($config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function getSenderIds(string $key, array $config): array
    {
        $adapter = $this->adapter($key);

        if (! $adapter) {
            return ['success' => false, 'message' => __('sms.providers.unsupported', ['key' => $key])];
        }

        return $adapter->getSenderIds($config);
    }
}
