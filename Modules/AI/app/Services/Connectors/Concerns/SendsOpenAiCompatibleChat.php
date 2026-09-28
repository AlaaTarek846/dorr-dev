<?php

namespace Modules\AI\Services\Connectors\Concerns;

use Illuminate\Support\Str;
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
            ];

            // Real, observed gap: OpenAI's reasoning-tier models (the o1/
            // o3/o4 family, and the gpt-5 family, which is reasoning-based
            // by default) reject any `temperature` other than their fixed
            // default of 1 - the API call fails outright with "Unsupported
            // value: 'temperature' does not support 0.7 with this model.
            // Only the default (1) value is supported.", which used to
            // take the whole chat/image-explain request down instead of
            // just skipping a setting that model does not honor anyway.
            // Sending no `temperature` key at all (rather than sending 1
            // explicitly) is what these models actually expect - they use
            // their own fixed default. The same models also reject the
            // classic `max_tokens` parameter and require the newer
            // `max_completion_tokens` name instead, so that is routed the
            // same way below.
            if ($this->modelSupportsCustomTemperature($provider->model)) {
                $payload['temperature'] = $provider->temperature !== null ? (float) $provider->temperature : 0.7;
            }

            if ($provider->max_tokens) {
                $tokensParam = $this->modelSupportsCustomTemperature($provider->model) ? 'max_tokens' : 'max_completion_tokens';
                $payload[$tokensParam] = $provider->max_tokens;
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

    /**
     * Naming-pattern check (not the ai_provider_models "reasoning"
     * capability tag, which drives routing decisions, not this API
     * constraint): true for anything that is NOT a known reasoning-tier
     * model. Kept independent of the registry so this still protects an
     * unregistered/legacy $provider->model value, not only models the
     * admin has explicitly tagged.
     */
    protected function modelSupportsCustomTemperature(?string $modelId): bool
    {
        if (! $modelId) {
            return true;
        }

        $id = Str::lower($modelId);

        if (Str::startsWith($id, ['o1', 'o3', 'o4', 'o5'])) {
            return false;
        }

        if (str_contains($id, 'gpt-5') || str_contains($id, 'reasoning') || str_contains($id, 'thinking')) {
            return false;
        }

        return true;
    }
}
