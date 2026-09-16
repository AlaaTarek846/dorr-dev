<?php

namespace Modules\AI\Services\Connectors;

use Modules\AI\Models\AiProvider;

class GoogleConnector extends AbstractHttpConnector
{
    protected function providerKey(): string
    {
        return 'google';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta';
    }

    public function testConnection(AiProvider $provider): array
    {
        return $this->attempt(function () use ($provider) {
            if (! $provider->hasApiKey()) {
                return $this->failure(__('ai.api_key_missing'));
            }

            $response = $this->client()
                ->get($this->baseUrl($provider).'/models', [
                    'key' => $provider->api_key,
                ]);

            if (! $response->successful()) {
                return $this->failure($this->errorMessageFromResponse($response));
            }

            $models = collect($response->json('models', []))
                ->map(fn ($model) => str_replace('models/', '', (string) ($model['name'] ?? '')))
                ->filter()
                ->all();

            if ($provider->model && $models !== [] && ! in_array($provider->model, $models, true)) {
                return $this->success(__('ai.connected_but_model_missing', [
                    'model' => $provider->model,
                    'count' => count($models),
                ]), $models);
            }

            return $this->success(__('ai.connected_with_models', ['count' => count($models)]), $models);
        });
    }
}
