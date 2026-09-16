<?php

namespace Modules\AI\Services;

use Modules\AI\Enums\AiProviderKey;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\Connectors\AnthropicConnector;
use Modules\AI\Services\Connectors\Contracts\AiConnector;
use Modules\AI\Services\Connectors\GoogleConnector;
use Modules\AI\Services\Connectors\GroqConnector;
use Modules\AI\Services\Connectors\OpenAiConnector;
use RuntimeException;

class AiConnectionTester
{
    /**
     * @return array{success: bool, message: string}
     */
    public function test(AiProvider $provider): array
    {
        return $this->connectorFor($provider)->testConnection($provider);
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
