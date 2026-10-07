<?php

namespace Modules\AI\Services\Connectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\Connectors\Contracts\AiConnector;
use Throwable;

abstract class AbstractHttpConnector implements AiConnector
{
    abstract protected function providerKey(): string;

    abstract protected function defaultBaseUrl(): string;

    /**
     * @return array{success: bool, message: string, models: list<string>}
     */
    abstract public function testConnection(AiProvider $provider): array;

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{success: bool, message: string, content: ?string}
     */
    abstract public function sendChat(AiProvider $provider, array $messages, bool $useWebSearch = false): array;

    /**
     * Default: no embeddings support. Only connectors that actually
     * implement a real embeddings call (currently OpenAiConnector) should
     * override this - never fake a vector here.
     *
     * @return array{success: bool, message: string, vector: ?list<float>}
     */
    public function embed(AiProvider $provider, string $text): array
    {
        return ['success' => false, 'message' => __('ai.embeddings_not_supported', ['provider' => $provider->name]), 'vector' => null];
    }

    /**
     * Default: no image editing support. Only a connector that actually
     * implements a real image-edit call (currently OpenAiConnector)
     * should override this - never fake an edited image here.
     *
     * @return array{success: bool, message: string, image: ?array{base64: string, mime: string}}
     */
    public function editImage(AiProvider $provider, string $modelKey, string $imageBytes, string $imageMime, string $prompt): array
    {
        return ['success' => false, 'message' => __('ai.image_editing_not_supported', ['provider' => $provider->name]), 'image' => null];
    }

    /** @return list<int> */
    public function videoDurationOptions(AiProvider $provider, string $modelKey): array
    {
        return [];
    }

    /** @return array{success: bool, message: string, job_id: ?string} */
    public function startVideo(AiProvider $provider, string $modelKey, string $prompt, int $seconds): array
    {
        return ['success' => false, 'message' => __('ai.video_generation_not_supported', ['provider' => $provider->name]), 'job_id' => null];
    }

    /** @return array{success: bool, message: string, state: string, progress: ?int} */
    public function pollVideo(AiProvider $provider, string $jobId): array
    {
        return ['success' => false, 'message' => __('ai.video_generation_not_supported', ['provider' => $provider->name]), 'state' => 'failed', 'progress' => null];
    }

    /** @return array{success: bool, message: string} */
    public function downloadVideo(AiProvider $provider, string $jobId, string $destinationPath): array
    {
        return ['success' => false, 'message' => __('ai.video_generation_not_supported', ['provider' => $provider->name])];
    }

    /**
     * Default: no text-to-image generation support. Only a connector that
     * actually implements a real image-generation call (currently
     * OpenAiConnector) should override this - never fake a picture here.
     *
     * @return array{success: bool, message: string, image: ?array{base64: string, mime: string}}
     */
    public function generateImage(AiProvider $provider, string $modelKey, string $prompt): array
    {
        return ['success' => false, 'message' => __('ai.image_generation_not_supported', ['provider' => $provider->name]), 'image' => null];
    }

    /**
     * Default: no speech-to-text support. Only a connector that actually
     * implements a real transcription call (currently OpenAiConnector)
     * should override this - never fake a transcript here.
     *
     * @return array{success: bool, message: string, text: ?string}
     */
    public function transcribeAudio(AiProvider $provider, string $modelKey, string $audioBytes, string $audioMime, ?string $languageHint = null): array
    {
        return ['success' => false, 'message' => __('ai.speech_to_text_not_supported', ['provider' => $provider->name]), 'text' => null];
    }

    /**
     * Default: no text-to-speech support. Only a connector that actually
     * implements a real TTS call (currently OpenAiConnector) should
     * override this - never fake audio here.
     *
     * @return array{success: bool, message: string, audio: ?array{base64: string, mime: string}}
     */
    public function synthesizeSpeech(AiProvider $provider, string $modelKey, string $text): array
    {
        return ['success' => false, 'message' => __('ai.text_to_speech_not_supported', ['provider' => $provider->name]), 'audio' => null];
    }

    /**
     * Default: no Realtime session support. Only a connector that
     * actually implements a real ephemeral-session call (currently
     * OpenAiConnector) should override this - never fake a session
     * credential here, matching the synthesizeSpeech() precedent above.
     *
     * @param  array{voice?: string, instructions?: ?string}  $options
     * @return array{success: bool, message: string, session: ?array{client_secret: string, expires_at: ?int, model: string}}
     */
    public function createRealtimeSession(AiProvider $provider, string $modelKey, array $options = []): array
    {
        return ['success' => false, 'message' => __('ai.realtime_not_supported', ['provider' => $provider->name]), 'session' => null];
    }

    protected function baseUrl(AiProvider $provider): string
    {
        $baseUrl = $provider->base_url ?: config("ai.providers.{$this->providerKey()}.base_url") ?: $this->defaultBaseUrl();

        return rtrim((string) $baseUrl, '/');
    }

    protected function client(): PendingRequest
    {
        return Http::timeout((int) config('ai.request_timeout', 20))->acceptJson();
    }

    /**
     * @param  list<string>  $models
     */
    protected function success(string $message, array $models = []): array
    {
        return ['success' => true, 'message' => $message, 'models' => $models, 'content' => null];
    }

    protected function failure(string $message): array
    {
        return ['success' => false, 'message' => $message, 'models' => [], 'content' => null];
    }

    protected function chatReply(string $content): array
    {
        return ['success' => true, 'message' => '', 'models' => [], 'content' => $content];
    }

    /**
     * Run the given callback and normalize connection-level exceptions into
     * a failed result instead of letting them bubble up. Shared by
     * testConnection() and sendChat() implementations.
     */
    protected function attempt(callable $callback): array
    {
        try {
            return $callback();
        } catch (ConnectionException $exception) {
            return $this->failure(__('ai.connection_failed', ['message' => $exception->getMessage()]));
        } catch (Throwable $exception) {
            return $this->failure(__('ai.unexpected_error', ['message' => $exception->getMessage()]));
        }
    }

    protected function errorMessageFromResponse(Response $response): string
    {
        $body = $response->json();

        $message = $body['error']['message']
            ?? $body['message']
            ?? null;

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return __('ai.http_error', ['status' => $response->status()]);
    }
}
