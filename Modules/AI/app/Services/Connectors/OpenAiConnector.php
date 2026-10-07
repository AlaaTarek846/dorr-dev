<?php

namespace Modules\AI\Services\Connectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
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
     * Real image editing via OpenAI's /images/edits endpoint (gpt-image-1) -
     * multipart upload of the source image plus a text instruction,
     * unlike every other call here which is JSON. gpt-image-1 always
     * returns base64 (no response_format=url option), which is exactly
     * the shape AiChatService needs to store the result as a normal
     * ai_conversation_attachments row - no separate download step to
     * fetch a URL and no signed link that could expire.
     *
     * @return array{success: bool, message: string, image: ?array{base64: string, mime: string}}
     */
    public function editImage(AiProvider $provider, string $modelKey, string $imageBytes, string $imageMime, string $prompt): array
    {
        if (! $provider->hasApiKey()) {
            return ['success' => false, 'message' => __('ai.api_key_missing'), 'image' => null];
        }

        $extension = match ($imageMime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        try {
            $response = $this->client()
                ->withHeaders(['Authorization' => 'Bearer '.$provider->api_key])
                ->attach('image', $imageBytes, "source.{$extension}")
                ->post($this->baseUrl($provider).'/images/edits', [
                    'model' => $modelKey ?: 'gpt-image-1',
                    'prompt' => $prompt,
                    'n' => 1,
                    'size' => 'auto',
                ]);

            if (! $response->successful()) {
                return ['success' => false, 'message' => $this->errorMessageFromResponse($response), 'image' => null];
            }

            $base64 = $response->json('data.0.b64_json');

            if (! is_string($base64) || $base64 === '') {
                return ['success' => false, 'message' => __('ai.empty_reply'), 'image' => null];
            }

            return ['success' => true, 'message' => '', 'image' => ['base64' => $base64, 'mime' => 'image/png']];
        } catch (ConnectionException $exception) {
            // Real, observed gap: a bare catch(Throwable) here put a real
            // network-level failure ("Connection refused" - the same
            // class of local network/AV/DPI issue already diagnosed for
            // large image payloads earlier in this project) behind the
            // generic "unexpected_error" message, which used to read as
            // "an error occurred while testing the connection" even
            // though nothing was being tested - deeply confusing for an
            // image-edit failure. Distinguished the same way
            // AbstractHttpConnector::attempt() already does for
            // testConnection()/sendChat(), so this gets the accurate
            // "could not reach the provider" message instead.
            return ['success' => false, 'message' => __('ai.connection_failed', ['message' => $exception->getMessage()]), 'image' => null];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => __('ai.unexpected_error', ['message' => $exception->getMessage()]), 'image' => null];
        }
    }

    /**
     * Real text-to-image generation via OpenAI's /images/generations
     * endpoint (gpt-image-1) - a plain JSON request (no source image to
     * upload, unlike editImage() above), used when the user is asking for
     * a brand new image from a description rather than a change to an
     * existing one. Same b64_json response shape as editImage(), so it
     * reuses the exact same storage mechanism
     * (AiChatService::storeGeneratedImageAttachment()).
     *
     * @return array{success: bool, message: string, image: ?array{base64: string, mime: string}}
     */
    public function generateImage(AiProvider $provider, string $modelKey, string $prompt): array
    {
        if (! $provider->hasApiKey()) {
            return ['success' => false, 'message' => __('ai.api_key_missing'), 'image' => null];
        }

        try {
            $response = $this->client()
                ->withHeaders(['Authorization' => 'Bearer '.$provider->api_key])
                ->post($this->baseUrl($provider).'/images/generations', [
                    'model' => $modelKey ?: 'gpt-image-1',
                    'prompt' => $prompt,
                    'n' => 1,
                    'size' => 'auto',
                ]);

            if (! $response->successful()) {
                return ['success' => false, 'message' => $this->errorMessageFromResponse($response), 'image' => null];
            }

            $base64 = $response->json('data.0.b64_json');

            if (! is_string($base64) || $base64 === '') {
                return ['success' => false, 'message' => __('ai.empty_reply'), 'image' => null];
            }

            return ['success' => true, 'message' => '', 'image' => ['base64' => $base64, 'mime' => 'image/png']];
        } catch (ConnectionException $exception) {
            return ['success' => false, 'message' => __('ai.connection_failed', ['message' => $exception->getMessage()]), 'image' => null];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => __('ai.unexpected_error', ['message' => $exception->getMessage()]), 'image' => null];
        }
    }

    /**
     * OpenAI Sora (POST /videos, GET /videos/{id}, GET /videos/{id}/content).
     * Clip lengths per OpenAI's documented values for Sora 2.
     *
     * @return list<int>
     */
    public function videoDurationOptions(AiProvider $provider, string $modelKey): array
    {
        // OpenAI shut the Sora Videos API down on 2026-09-24 (no replacement), so
        // no OpenAI model is offered for video. Set AI_OPENAI_VIDEO_ENABLED=true
        // only if OpenAI ever brings a /videos endpoint back.
        return config('ai.video.openai_enabled', false) ? [4, 8, 12] : [];
    }

    /** @return array{success: bool, message: string, job_id: ?string} */
    public function startVideo(AiProvider $provider, string $modelKey, string $prompt, int $seconds): array
    {
        if (! $provider->hasApiKey()) {
            return ['success' => false, 'message' => __('ai.api_key_missing'), 'job_id' => null];
        }

        try {
            $response = $this->client()
                ->withHeaders(['Authorization' => 'Bearer '.$provider->api_key])
                ->post($this->baseUrl($provider).'/videos', [
                    'model' => $modelKey ?: 'sora-2',
                    'prompt' => $prompt,
                    'seconds' => (string) $seconds,
                    'size' => (string) config('ai.video.size', '1280x720'),
                ]);

            if (! $response->successful()) {
                return ['success' => false, 'message' => $this->errorMessageFromResponse($response), 'job_id' => null];
            }

            $id = $response->json('id');

            if (! is_string($id) || $id === '') {
                return ['success' => false, 'message' => __('ai.empty_reply'), 'job_id' => null];
            }

            return ['success' => true, 'message' => '', 'job_id' => $id];
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
                ->withHeaders(['Authorization' => 'Bearer '.$provider->api_key])
                ->get($this->baseUrl($provider).'/videos/'.rawurlencode($jobId));

            if (! $response->successful()) {
                return ['success' => false, 'message' => $this->errorMessageFromResponse($response), 'state' => 'failed', 'progress' => null];
            }

            $status = (string) $response->json('status');
            $progress = $response->json('progress');
            $progress = is_numeric($progress) ? (int) $progress : null;

            return match ($status) {
                'completed' => ['success' => true, 'message' => '', 'state' => 'completed', 'progress' => 100],
                'failed', 'cancelled', 'canceled', 'expired' => ['success' => false, 'message' => (string) ($response->json('error.message') ?? $status), 'state' => 'failed', 'progress' => $progress],
                'queued' => ['success' => true, 'message' => '', 'state' => 'queued', 'progress' => $progress],
                default => ['success' => true, 'message' => '', 'state' => 'processing', 'progress' => $progress],
            };
        } catch (\Throwable $exception) {
            // A network blip while polling must not fail a job that is still running at the provider.
            return ['success' => true, 'message' => $exception->getMessage(), 'state' => 'processing', 'progress' => null];
        }
    }

    /** @return array{success: bool, message: string} */
    public function downloadVideo(AiProvider $provider, string $jobId, string $destinationPath): array
    {
        try {
            $response = Http::timeout((int) config('ai.video.download_timeout', 180))
                ->withHeaders(['Authorization' => 'Bearer '.$provider->api_key])
                ->sink($destinationPath)
                ->get($this->baseUrl($provider).'/videos/'.rawurlencode($jobId).'/content');

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
        } catch (ConnectionException $exception) {
            return ['success' => false, 'message' => __('ai.connection_failed', ['message' => $exception->getMessage()]), 'vector' => null];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => __('ai.unexpected_error', ['message' => $exception->getMessage()]), 'vector' => null];
        }
    }

    /**
     * Real speech-to-text via OpenAI's /audio/transcriptions endpoint -
     * multipart upload of the raw voice-message bytes, same shape as
     * editImage()'s multipart upload above. Verified against OpenAI's
     * current API reference before writing this (2026-09-28): endpoint,
     * field names and the {"text": ...} response shape are all confirmed
     * live, unlike the Sora video API which turned out to have been shut
     * down entirely - deliberately NOT building that one blind.
     *
     * @return array{success: bool, message: string, text: ?string}
     */
    public function transcribeAudio(AiProvider $provider, string $modelKey, string $audioBytes, string $audioMime, ?string $languageHint = null): array
    {
        if (! $provider->hasApiKey()) {
            return ['success' => false, 'message' => __('ai.api_key_missing'), 'text' => null];
        }

        $extension = match ($audioMime) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/webm' => 'webm',
            'audio/ogg' => 'ogg',
            default => 'mp3',
        };

        // Root-cause fix: without OpenAI's own "language" field, Whisper
        // auto-detects the spoken language, and short/accented Arabic
        // voice messages routinely get misdetected (wrong language
        // entirely, or a mangled mixed-script transcript) - the
        // downstream reply then looks like it's in a "weird language"
        // when really the transcript it answered was already wrong.
        // Giving Whisper the ISO-639-1 hint it already supports fixes
        // this at the source instead of trying to patch it after the
        // fact in the chat reply.
        $fields = [
            'model' => $modelKey ?: 'whisper-1',
            'response_format' => 'json',
        ];

        if ($languageHint !== null && $languageHint !== '') {
            $fields['language'] = $languageHint;
        }

        try {
            $response = $this->client()
                ->withHeaders(['Authorization' => 'Bearer '.$provider->api_key])
                ->attach('file', $audioBytes, "voice-message.{$extension}")
                ->post($this->baseUrl($provider).'/audio/transcriptions', $fields);

            if (! $response->successful()) {
                return ['success' => false, 'message' => $this->errorMessageFromResponse($response), 'text' => null];
            }

            $text = $response->json('text');

            if (! is_string($text) || trim($text) === '') {
                return ['success' => false, 'message' => __('ai.empty_reply'), 'text' => null];
            }

            return ['success' => true, 'message' => '', 'text' => $text];
        } catch (ConnectionException $exception) {
            return ['success' => false, 'message' => __('ai.connection_failed', ['message' => $exception->getMessage()]), 'text' => null];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => __('ai.unexpected_error', ['message' => $exception->getMessage()]), 'text' => null];
        }
    }

    /**
     * Real text-to-speech via OpenAI's /audio/speech endpoint - unlike
     * every other call in this class, the response body IS the audio
     * itself (raw bytes, not JSON), so this reads $response->body()
     * directly instead of ->json(). Verified against OpenAI's current API
     * reference before writing this (2026-09-28).
     *
     * @return array{success: bool, message: string, audio: ?array{base64: string, mime: string}}
     */
    public function synthesizeSpeech(AiProvider $provider, string $modelKey, string $text): array
    {
        if (! $provider->hasApiKey()) {
            return ['success' => false, 'message' => __('ai.api_key_missing'), 'audio' => null];
        }

        try {
            $response = $this->client()
                ->withHeaders(['Authorization' => 'Bearer '.$provider->api_key])
                ->post($this->baseUrl($provider).'/audio/speech', [
                    'model' => $modelKey ?: 'tts-1',
                    'input' => $text,
                    'voice' => config('ai.chat.voice_reply.voice', 'alloy'),
                    'response_format' => 'mp3',
                ]);

            if (! $response->successful()) {
                return ['success' => false, 'message' => $this->errorMessageFromResponse($response), 'audio' => null];
            }

            $bytes = $response->body();

            if ($bytes === '' || $bytes === null) {
                return ['success' => false, 'message' => __('ai.empty_reply'), 'audio' => null];
            }

            return ['success' => true, 'message' => '', 'audio' => ['base64' => base64_encode($bytes), 'mime' => 'audio/mpeg']];
        } catch (ConnectionException $exception) {
            return ['success' => false, 'message' => __('ai.connection_failed', ['message' => $exception->getMessage()]), 'audio' => null];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => __('ai.unexpected_error', ['message' => $exception->getMessage()]), 'audio' => null];
        }
    }

    /**
     * Real Realtime session credential via OpenAI's /realtime/client_secrets
     * endpoint (verified against the current official API reference,
     * 2026-09-29 - developers.openai.com/api/reference/resources/realtime/
     * subresources/client_secrets/methods/create - not an old example).
     * This mints a short-lived credential server-side and hands only that
     * back to the caller; the raw $provider->api_key never leaves this
     * method, matching section 36's "never expose OPENAI_API_KEY" rule.
     * The Android client takes the returned client_secret and opens its
     * OWN WebRTC connection straight to OpenAI's /realtime/calls endpoint
     * with it - this call never touches the live audio itself.
     *
     * The reference documents the request shape precisely but is
     * genuinely incomplete on the exact response field path for the
     * secret/expiry (confirmed by direct inspection of the current page,
     * not assumed) - Arr::get() is tried against every plausible shape
     * seen across OpenAI's own docs and their Azure-hosted mirror rather
     * than guessing a single one, and this explicitly fails (rather than
     * silently returning a mangled/empty credential) if none match, so a
     * real shape change surfaces immediately instead of quietly breaking
     * every voice call.
     *
     * @param  array{voice?: string, instructions?: ?string}  $options
     * @return array{success: bool, message: string, session: ?array{client_secret: string, expires_at: ?int, model: string}}
     */
    public function createRealtimeSession(AiProvider $provider, string $modelKey, array $options = []): array
    {
        if (! $provider->hasApiKey()) {
            return ['success' => false, 'message' => __('ai.api_key_missing'), 'session' => null];
        }

        $session = ['type' => 'realtime', 'model' => $modelKey];

        if (filled($options['instructions'] ?? null)) {
            $session['instructions'] = $options['instructions'];
        }

        $session['audio'] = ['output' => ['voice' => $options['voice'] ?? config('ai.chat.voice_reply.voice', 'alloy')]];

        try {
            $response = $this->client()
                ->withHeaders(['Authorization' => 'Bearer '.$provider->api_key])
                ->post($this->baseUrl($provider).'/realtime/client_secrets', [
                    'session' => $session,
                ]);

            if (! $response->successful()) {
                return ['success' => false, 'message' => $this->errorMessageFromResponse($response), 'session' => null];
            }

            $body = $response->json();

            $secret = Arr::get($body, 'client_secret.value')
                ?? Arr::get($body, 'value')
                ?? Arr::get($body, 'client_secret');

            if (! is_string($secret) || $secret === '') {
                return ['success' => false, 'message' => __('ai.empty_reply'), 'session' => null];
            }

            $expiresAt = Arr::get($body, 'client_secret.expires_at') ?? Arr::get($body, 'expires_at');

            return [
                'success' => true,
                'message' => '',
                'session' => [
                    'client_secret' => $secret,
                    'expires_at' => is_numeric($expiresAt) ? (int) $expiresAt : null,
                    'model' => $modelKey,
                ],
            ];
        } catch (ConnectionException $exception) {
            return ['success' => false, 'message' => __('ai.connection_failed', ['message' => $exception->getMessage()]), 'session' => null];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => __('ai.unexpected_error', ['message' => $exception->getMessage()]), 'session' => null];
        }
    }
}
