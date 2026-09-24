<?php

namespace Modules\AI\Services\Connectors;

use Illuminate\Support\Arr;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\Connectors\Concerns\SendsOpenAiCompatibleChat;

class OpenAiConnector extends AbstractHttpConnector
{
    use SendsOpenAiCompatibleChat;

    protected function providerKey(): string
    {
        return 'openai';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    public function testConnection(AiProvider $provider): array
    {
        return $this->attempt(function () use ($provider) {
            if (! $provider->hasApiKey()) {
                return $this->failure(__('ai.api_key_missing'));
            }

            $headers = [
                'Authorization' => 'Bearer '.$provider->api_key,
            ];

            $organization = Arr::get($provider->extra ?? [], 'organization');

            if (filled($organization)) {
                $headers['OpenAI-Organization'] = $organization;
            }

            $response = $this->client()
                ->withHeaders($headers)
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

    /**
     * Real embeddings support via OpenAI's /embeddings endpoint - the only
     * connector with a genuine implementation right now (Anthropic has no
     * embeddings API; Google/Groq are not wired up yet). The Knowledge
     * Retriever degrades to lexical-only search when this fails, rather
     * than pretending every provider can do this.
     *
     * @return array{success: bool, message: string, vector: ?list<float>}
     */
    public function embed(AiProvider $provider, string $text): array
    {
        if (! $provider->hasApiKey()) {
            return ['success' => false, 'message' => __('ai.api_key_missing'), 'vector' => null];
        }

        try {
            $response = $this->client()
                ->withHeaders(['Authorization' => 'Bearer '.$provider->api_key])
                ->post($this->baseUrl($provider).'/embeddings', [
                    'model' => config('ai.knowledge.embedding_model', 'text-embedding-3-small'),
                    'input' => $text,
                ]);

            if (! $response->successful()) {
                return ['success' => false, 'message' => $this->errorMessageFromResponse($response), 'vector' => null];
            }

            $vector = $response->json('data.0.embedding');

            if (! is_array($vector) || $vector === []) {
                return ['success' => false, 'message' => __('ai.empty_reply'), 'vector' => null];
            }

            return ['success' => true, 'message' => '', 'vector' => array_map('floatval', $vector)];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => __('ai.unexpected_error', ['message' => $exception->getMessage()]), 'vector' => null];
        }
    }
}
