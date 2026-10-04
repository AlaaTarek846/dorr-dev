<?php

namespace Modules\AI\Services;

use Modules\AI\Enums\AiProviderKey;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\Connectors\AnthropicConnector;
use Modules\AI\Services\Connectors\Contracts\AiConnector;
use Modules\AI\Services\Connectors\Contracts\TranscribesAudio;
use Modules\AI\Services\Connectors\GoogleConnector;
use Modules\AI\Services\Connectors\GroqConnector;
use Modules\AI\Services\Connectors\OpenAiConnector;
use RuntimeException;

/**
 * Single entry point for talking to whichever provider a given AiProvider
 * row represents - resolves the right connector once, and exposes both the
 * admin "test connection" action and the user-facing chat dispatch through
 * it, so the two never drift into different provider-handling logic.
 */
class AiGateway
{
    /**
     * @return array{success: bool, message: string, models: list<string>}
     */
    public function test(AiProvider $provider): array
    {
        return $this->connectorFor($provider)->testConnection($provider);
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{success: bool, message: string, content: ?string}
     */
    public function chat(AiProvider $provider, array $messages): array
    {
        return $this->connectorFor($provider)->sendChat($provider, $messages);
    }

    /**
     * Speech to text, for providers that take audio (OpenAI, Groq, Google — not Anthropic).
     *
     * @return array{success: bool, message: string, content: ?string}
     */
    public function transcribe(AiProvider $provider, string $path, string $mime): array
    {
        $connector = $this->connectorFor($provider);

        if (! $connector instanceof TranscribesAudio) {
            return ['success' => false, 'message' => __('ai.transcription_unsupported'), 'content' => null];
        }

        return $connector->transcribe($provider, $path, $mime);
    }

    public function canTranscribe(AiProvider $provider): bool
    {
        return $this->connectorFor($provider) instanceof TranscribesAudio;
    }

    protected function connectorFor(AiProvider $provider): AiConnector
    {
        $key = AiProviderKey::tryFrom($provider->key);

        return match ($key) {
            AiProviderKey::OpenAi => new OpenAiConnector,
            AiProviderKey::Anthropic => new AnthropicConnector,
            AiProviderKey::Google => new GoogleConnector,
            AiProviderKey::Groq => new GroqConnector,
            default => throw new RuntimeException("Unsupported AI provider [{$provider->key}]."),
        };
    }
}
