<?php

namespace Modules\AI\Services\Connectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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

    public function sendChat(AiProvider $provider, array $messages, bool $useWebSearch = false): array
    {
        // No real hosted web-search integration wired for Google in this
        // codebase yet - the flag is accepted (interface compatibility)
        // but deliberately ignored rather than faking search results.
        unset($useWebSearch);

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
                    // AiGateway::formatMultimodalContent() already builds a
                    // Gemini-shaped parts array (text + inline_data) for a
                    // vision turn - only plain string content needs
                    // wrapping into a single text part here.
                    'parts' => is_array($message['content']) ? $message['content'] : [['text' => $message['content']]],
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

    /**
     * Google Veo through the Gemini API (models/{id}:predictLongRunning).
     * Clip lengths per Google's Veo documentation: 4, 6 or 8 seconds.
     *
     * @return list<int>
     */
    public function videoDurationOptions(AiProvider $provider, string $modelKey): array
    {
        return str_contains(strtolower($modelKey), 'veo') ? [4, 6, 8] : [];
    }

    /** @return array{success: bool, message: string, job_id: ?string} */
    public function startVideo(AiProvider $provider, string $modelKey, string $prompt, int $seconds): array
    {
        if (! $provider->hasApiKey()) {
            return ['success' => false, 'message' => __('ai.api_key_missing'), 'job_id' => null];
        }

        try {
            $response = $this->client()
                ->withHeaders(['x-goog-api-key' => (string) $provider->api_key])
                ->post($this->baseUrl($provider).'/models/'.rawurlencode($modelKey).':predictLongRunning', [
                    'instances' => [['prompt' => $prompt]],
                    'parameters' => [
                        'aspectRatio' => (string) config('ai.video.aspect_ratio', '16:9'),
                        'durationSeconds' => (string) $seconds,
                    ],
                ]);

            if (! $response->successful()) {
                return ['success' => false, 'message' => $this->errorMessageFromResponse($response), 'job_id' => null];
            }

            $name = $response->json('name');

            if (! is_string($name) || $name === '') {
                return ['success' => false, 'message' => __('ai.empty_reply'), 'job_id' => null];
            }

            return ['success' => true, 'message' => '', 'job_id' => $name];
        } catch (ConnectionException $exception) {
            return ['success' => false, 'message' => __('ai.connection_failed', ['message' => $exception->getMessage()]), 'job_id' => null];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => __('ai.unexpected_error', ['message' => $exception->getMessage()]), 'job_id' => null];
        }
    }

    /** @return array{success: bool, message: string, state: string, progress: ?int} */
    public function pollVideo(AiProvider $provider, string $jobId): array
    {
        try {
            $response = $this->client()
                ->withHeaders(['x-goog-api-key' => (string) $provider->api_key])
                ->get($this->baseUrl($provider).'/'.ltrim($jobId, '/'));

            if (! $response->successful()) {
                return ['success' => false, 'message' => $this->errorMessageFromResponse($response), 'state' => 'failed', 'progress' => null];
            }

            if (! $response->json('done')) {
                return ['success' => true, 'message' => '', 'state' => 'processing', 'progress' => null];
            }

            if ($response->json('error')) {
                return ['success' => false, 'message' => (string) ($response->json('error.message') ?? 'failed'), 'state' => 'failed', 'progress' => null];
            }

            if ($this->videoUriFrom($response->json()) === null) {
                // Finished but nothing to download - typically a safety/policy filter.
                $reason = $response->json('response.generateVideoResponse.raiMediaFilteredReasons.0');

                return ['success' => false, 'message' => is_string($reason) ? $reason : __('ai.empty_reply'), 'state' => 'failed', 'progress' => null];
            }

            return ['success' => true, 'message' => '', 'state' => 'completed', 'progress' => 100];
        } catch (\Throwable $exception) {
            // A network blip while polling must not fail a job that is still running at the provider.
            return ['success' => true, 'message' => $exception->getMessage(), 'state' => 'processing', 'progress' => null];
        }
    }

    /** @return array{success: bool, message: string} */
    public function downloadVideo(AiProvider $provider, string $jobId, string $destinationPath): array
    {
        try {
            $operation = $this->client()
                ->withHeaders(['x-goog-api-key' => (string) $provider->api_key])
                ->get($this->baseUrl($provider).'/'.ltrim($jobId, '/'));

            $uri = $operation->successful() ? $this->videoUriFrom($operation->json()) : null;

            if ($uri === null) {
                return ['success' => false, 'message' => $operation->successful() ? __('ai.empty_reply') : $this->errorMessageFromResponse($operation)];
            }

            $response = Http::timeout((int) config('ai.video.download_timeout', 180))
                ->withHeaders(['x-goog-api-key' => (string) $provider->api_key])
                ->withOptions(['allow_redirects' => true])
                ->sink($destinationPath)
                ->get($uri);

            if (! $response->successful()) {
                @unlink($destinationPath);

                return ['success' => false, 'message' => $this->errorMessageFromResponse($response)];
            }

            if (! is_file($destinationPath) || filesize($destinationPath) === 0) {
                return ['success' => false, 'message' => __('ai.empty_reply')];
            }

            return ['success' => true, 'message' => ''];
        } catch (\Throwable $exception) {
            @unlink($destinationPath);

            return ['success' => false, 'message' => __('ai.unexpected_error', ['message' => $exception->getMessage()])];
        }
    }

    /** @param  array<string, mixed>|null  $operation */
    protected function videoUriFrom(?array $operation): ?string
    {
        $uri = data_get($operation, 'response.generateVideoResponse.generatedSamples.0.video.uri')
            ?? data_get($operation, 'response.generatedVideos.0.video.uri');

        return is_string($uri) && $uri !== '' ? $uri : null;
    }
}
