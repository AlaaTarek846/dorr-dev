<?php

namespace Modules\AI\Services;

use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Modules\AI\Http\Resources\AiProviderResource;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;

class AiProviderService
{
    public function __construct(
        protected AiProviderRepository $repository,
        protected AiGateway $gateway,
    ) {}

    public function list(): JsonResponse
    {
        $providers = $this->repository->all();

        return ApiResponse::success(
            AiProviderResource::collection($providers),
            __('api.retrieved'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $key, array $data): JsonResponse
    {
        $provider = $this->repository->findByKey($key);

        $payload = $this->buildUpdatePayload($provider, $data);

        $this->assertEnableIsPossible($provider, $payload);
        $this->clearDefaultIfNoLongerUsable($provider, $payload);

        $provider = $this->repository->updateByKey($key, $payload);

        return ApiResponse::success(
            new AiProviderResource($provider),
            __('api.updated'),
        );
    }

    public function setDefault(string $key): JsonResponse
    {
        $provider = $this->repository->findByKey($key);

        if (! $provider->isUsableForChat()) {
            throw ValidationException::withMessages([
                'is_default' => [__('ai.default_requires_enabled_and_key')],
            ]);
        }

        $provider = $this->repository->setDefault($key);

        return ApiResponse::success(
            new AiProviderResource($provider),
            __('api.updated'),
        );
    }

    public function testConnection(string $key): JsonResponse
    {
        $provider = $this->repository->findByKey($key);
        $result = $this->gateway->test($provider);

        $updates = [
            'last_test_status' => $result['success'] ? 'success' : 'failed',
            'last_test_message' => $result['message'],
            'last_tested_at' => now(),
        ];

        // Only refresh the cached model list when we actually got one back,
        // so a failed test never wipes out a previously known-good list.
        if (($result['models'] ?? []) !== []) {
            $updates['available_models'] = $result['models'];
            $updates['available_models_synced_at'] = now();
        }

        $provider->update($updates);

        return ApiResponse::success(
            new AiProviderResource($provider->refresh()),
            $result['success'] ? __('ai.test_success') : __('ai.test_failed'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function buildUpdatePayload(AiProvider $provider, array $data): array
    {
        $payload = [];

        if (array_key_exists('is_enabled', $data)) {
            $payload['is_enabled'] = (bool) $data['is_enabled'];
        }

        if (Arr::get($data, 'clear_api_key') === true) {
            $payload['api_key'] = null;
        } elseif (filled(Arr::get($data, 'api_key'))) {
            $payload['api_key'] = $data['api_key'];
        }

        foreach (['model', 'base_url', 'temperature', 'max_tokens'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if (array_key_exists('organization', $data)) {
            $extra = $provider->extra ?? [];
            $extra['organization'] = $data['organization'] ?: null;
            $payload['extra'] = $extra;
        }

        return $payload;
    }

    /**
     * If this update turns the provider off or clears its key while it was
     * the chat default, drop the default flag too - a disabled provider
     * should never keep silently pointing the chat at itself.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function clearDefaultIfNoLongerUsable(AiProvider $provider, array &$payload): void
    {
        if (! $provider->is_default) {
            return;
        }

        $willBeEnabled = $payload['is_enabled'] ?? $provider->is_enabled;
        $willHaveApiKey = array_key_exists('api_key', $payload)
            ? filled($payload['api_key'])
            : $provider->hasApiKey();

        if (! $willBeEnabled || ! $willHaveApiKey) {
            $payload['is_default'] = false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function assertEnableIsPossible(AiProvider $provider, array $payload): void
    {
        $willBeEnabled = $payload['is_enabled'] ?? $provider->is_enabled;

        if (! $willBeEnabled) {
            return;
        }

        $willHaveApiKey = array_key_exists('api_key', $payload)
            ? filled($payload['api_key'])
            : $provider->hasApiKey();

        if (! $willHaveApiKey) {
            throw ValidationException::withMessages([
                'api_key' => [__('ai.api_key_required_to_enable')],
            ]);
        }
    }
}
