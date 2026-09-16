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

    public function sendChat(AiProvider $provider, array $messages): array
    {
        return $this->attempt(function () use ($provider, $messages) {
            if (! $provider->hasApiKey()) {
                return $this->failure(__('ai.api_key_missing'));
            }

            // Gemini has no "system" role either, and unlike OpenAI it calls
            // the assistant turn "model" instead of "assistant".
            $system = collect($messages)
                ->where('role', 'system')
                ->pluck('content')
                ->implode("\n\n");

            $contents = collect($messages)
                ->where('role', '!=', 'system')
                ->map(fn (array $message) => [
                    'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $message['content']]],
                ])
                ->values()
                ->all();

            $payload = [
                'contents' => $contents,
                'generationConfig' => array_filter([
                    'temperature' => $provider->temperature !== null ? (float) $provider->temperature : 0.7,
                    'maxOutputTokens' => $provider->max_tokens,
                ], fn ($value) => $value !== null),
            ];

            if ($system !== '') {
                $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
            }

            $model = $provider->model ?: config('ai.providers.google.default_model');

            // Http::post() sends its 2nd arg as the JSON body (unlike get(),
            // which treats it as query params), so the API key has to be
            // appended to the URL itself here.
            $url = $this->baseUrl($provider)."/models/{$model}:generateContent?key=".urlencode((string) $provider->api_key);

            $response = $this->client()->post($url, $payload);

            if (! $response->successful()) {
                return $this->failure($this->errorMessageFromResponse($response));
            }

            $content = collect($response->json('candidates.0.content.parts', []))
                ->pluck('text')
                ->implode('');

            if ($content === '') {
                return $this->failure(__('ai.empty_reply'));
            }

            return $this->chatReply($content);
        });
    }
}
