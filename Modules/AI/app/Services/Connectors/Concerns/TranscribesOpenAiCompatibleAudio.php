<?php

namespace Modules\AI\Services\Connectors\Concerns;

use Modules\AI\Models\AiProvider;

/**
 * OpenAI and Groq share the same /audio/transcriptions endpoint (Whisper); only the model differs
 * (`ai.providers.{key}.transcription_model`, or `extra.transcription_model` on the provider row).
 */
trait TranscribesOpenAiCompatibleAudio
{
    public function transcribe(AiProvider $provider, string $path, string $mime): array
    {
        return $this->attempt(function () use ($provider, $path) {
            if (! $provider->hasApiKey()) {
                return $this->failure(__('ai.api_key_missing'));
            }

            $model = $provider->extra['transcription_model'] ?? config("ai.providers.{$this->providerKey()}.transcription_model");

            $response = $this->client()
                ->timeout((int) config('ai.transcription_timeout', 60))
                ->withToken((string) $provider->api_key)
                ->attach('file', (string) file_get_contents($path), basename($path))
                ->post($this->baseUrl($provider).'/audio/transcriptions', [
                    'model' => $model,
                    'response_format' => 'json',
                ]);

            if (! $response->successful()) {
                return $this->failure($this->errorMessageFromResponse($response));
            }

            $text = trim((string) $response->json('text'));

            return $text === '' ? $this->failure(__('ai.empty_reply')) : $this->chatReply($text);
        });
    }
}
