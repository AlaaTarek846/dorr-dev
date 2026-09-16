<?php

namespace Modules\AI\Services\Connectors\Concerns;

use Modules\AI\Models\AiProvider;

/**
 * Groq exposes an OpenAI-compatible /chat/completions endpoint, so both
 * connectors share this implementation instead of duplicating it.
 */
trait SendsOpenAiCompatibleChat
{
    public function sendChat(AiProvider $provider, array $messages): array
    {
        return $this->attempt(function () use ($provider, $messages) {
            if (! $provider->hasApiKey()) {
                return $this->failure(__('ai.api_key_missing'));
            }

            $payload = [
                'model' => $provider->model,
                'messages' => array_map(
                    fn (array $message) => ['role' => $message['role'], 'content' => $message['content']],
                    $messages,
                ),
                'temperature' => $provider->temperature !== null ? (float) $provider->temperature : 0.7,
            ];

            if ($provider->max_tokens) {
                $payload['max_tokens'] = $provider->max_tokens;
            }

            $headers = ['Authorization' => 'Bearer '.$provider->api_key];

            $organization = $this->organizationHeader($provider);

            if ($organization !== null) {
                $headers['OpenAI-Organization'] = $organization;
            }

            $response = $this->client()
                ->withHeaders($headers)
                ->post($this->baseUrl($provider).'/chat/completions', $payload);

            if (! $response->successful()) {
                return $this->failure($this->errorMessageFromResponse($response));
            }

            $content = $response->json('choices.0.message.content');

            if (! is_string($content) || $content === '') {
                return $this->failure(__('ai.empty_reply'));
            }

            return $this->chatReply($content);
        });
    }

    /**
     * Only OpenAI itself supports the organization header; Groq ignores it
     * anyway, but we only send it when actually configured.
     */
    protected function organizationHeader(AiProvider $provider): ?string
    {
        $organization = $provider->extra['organization'] ?? null;

        return filled($organization) ? $organization : null;
    }
}
