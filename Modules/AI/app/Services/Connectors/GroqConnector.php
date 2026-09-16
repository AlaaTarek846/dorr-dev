<?php

namespace Modules\AI\Services\Connectors;

use Modules\AI\Models\AiProvider;
use Modules\AI\Services\Connectors\Concerns\SendsOpenAiCompatibleChat;

class GroqConnector extends AbstractHttpConnector
{
    use SendsOpenAiCompatibleChat;

    protected function providerKey(): string
    {
        return 'groq';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.groq.com/openai/v1';
    }

    public function testConnection(AiProvider $provider): array
    {
        return $this->attempt(function () use ($provider) {
            if (! $provider->hasApiKey()) {
                return $this->failure(__('ai.api_key_missing'));
            }

            $response = $this->client()
                ->withToken($provider->api_key)
                ->get($this->baseUrl($provider).'/models');

            if (! $response->successful()) {
                return $this->failure($this->errorMessageFromResponse($response));
            }

            $models = collect($response->json('data', []))->pluck('id')->all();

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
