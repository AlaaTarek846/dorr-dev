<?php

namespace Modules\AI\Services\Connectors;

use Modules\AI\Models\AiProvider;

class AnthropicConnector extends AbstractHttpConnector
{
    protected function providerKey(): string
    {
        return 'anthropic';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.anthropic.com/v1';
    }

    public function testConnection(AiProvider $provider): array
    {
        return $this->attempt(function () use ($provider) {
            if (! $provider->hasApiKey()) {
                return $this->failure(__('ai.api_key_missing'));
            }

            $response = $this->client()
                ->withHeaders([
                    'x-api-key' => $provider->api_key,
                    'anthropic-version' => '2023-06-01',
                ])
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

    public function sendChat(AiProvider $provider, array $messages): array
    {
        return $this->attempt(function () use ($provider, $messages) {
            if (! $provider->hasApiKey()) {
                return $this->failure(__('ai.api_key_missing'));
            }

            // Anthropic has no "system" role inside the messages array - any
            // system messages are concatenated into a separate top-level field.
            $system = collect($messages)
                ->where('role', 'system')
                ->pluck('content')
                ->implode("\n\n");

            $conversation = collect($messages)
                ->where('role', '!=', 'system')
                ->map(fn (array $message) => [
                    'role' => $message['role'] === 'assistant' ? 'assistant' : 'user',
                    'content' => $message['content'],
                ])
                ->values()
                ->all();

            $payload = [
                'model' => $provider->model,
                'messages' => $conversation,
                // Anthropic, unlike OpenAI/Groq, requires max_tokens on every request.
                'max_tokens' => $provider->max_tokens ?: config('ai.chat.default_max_tokens', 1024),
                'temperature' => $provider->temperature !== null ? (float) $provider->temperature : 0.7,
            ];

            if ($system !== '') {
                $payload['system'] = $system;
            }

            $response = $this->client()
                ->withHeaders([
                    'x-api-key' => $provider->api_key,
                    'anthropic-version' => '2023-06-01',
                ])
                ->post($this->baseUrl($provider).'/messages', $payload);

            if (! $response->successful()) {
                return $this->failure($this->errorMessageFromResponse($response));
            }

            $content = collect($response->json('content', []))
                ->where('type', 'text')
                ->pluck('text')
                ->implode('');

            if ($content === '') {
                return $this->failure(__('ai.empty_reply'));
            }

            return $this->chatReply($content);
        });
    }
}
