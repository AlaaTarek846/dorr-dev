<?php

namespace Modules\AI\Services;

use Modules\AI\Enums\AiProviderKey;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiProviderDataRule;
use Modules\AI\Models\AiProviderLog;
use Modules\AI\Models\AiRequest;
use Modules\AI\Services\Connectors\AnthropicConnector;
use Modules\AI\Services\Connectors\Contracts\AiConnector;
use Modules\AI\Services\Connectors\GoogleConnector;
use Modules\AI\Services\Connectors\GroqConnector;
use Modules\AI\Services\Connectors\OpenAiConnector;
use RuntimeException;

/**
 * Single entry point for talking to whichever provider a given AiProvider
 * row represents - resolves the right connector once, and exposes both the
 * admin "test connection" action and the user-facing chat dispatch through
 * it, so the two never drift into different provider-handling logic.
 *
 * Also the single choke point for two Phase 6 concerns that used to have
 * admin-configurable tables but no runtime behavior behind them: outgoing
 * PII/secret sanitization per provider (ai_provider_data_rules) and a
 * per-call audit trail (ai_provider_logs) - see chat()/embed().
 */
class AiGateway
{
    public function __construct(protected AiPiiSanitizer $sanitizer) {}

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
    public function chat(AiProvider $provider, array $messages, ?AiRequest $context = null): array
    {
        $messages = $this->applyDataRules($provider, $messages);

        $startedAt = microtime(true);
        $result = $this->connectorFor($provider)->sendChat($provider, $messages);
        $this->logCall($provider, $context, $startedAt, $result);

        return $result;
    }

    /**
     * @return array{success: bool, message: string, vector: ?list<float>}
     */
    public function embed(AiProvider $provider, string $text, ?AiRequest $context = null): array
    {
        $rule = $this->dataRuleFor($provider);

        if ($rule?->sanitize_pii) {
            $text = $this->sanitizer->redactPii($text);
        }

        if ($rule?->sanitize_secrets) {
            $text = $this->sanitizer->redactSecrets($text);
        }

        $startedAt = microtime(true);
        $result = $this->connectorFor($provider)->embed($provider, $text);
        $this->logCall($provider, $context, $startedAt, $result);

        return $result;
    }

    /**
     * Applies the provider's ai_provider_data_rules row (v2.0 doc, 17.2 -
     * PII/secret minimization before data leaves to an external model),
     * to the user/assistant turns only - never to our own system prompt,
     * which is not user-supplied content.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    protected function applyDataRules(AiProvider $provider, array $messages): array
    {
        $rule = $this->dataRuleFor($provider);

        if (! $rule || (! $rule->sanitize_pii && ! $rule->sanitize_secrets)) {
            return $messages;
        }

        return array_map(function (array $message) use ($rule) {
            if ($message['role'] === 'system') {
                return $message;
            }

            $content = $message['content'];

            if ($rule->sanitize_pii) {
                $content = $this->sanitizer->redactPii($content);
            }

            if ($rule->sanitize_secrets) {
                $content = $this->sanitizer->redactSecrets($content);
            }

            return ['role' => $message['role'], 'content' => $content];
        }, $messages);
    }

    protected function dataRuleFor(AiProvider $provider): ?AiProviderDataRule
    {
        return AiProviderDataRule::query()
            ->where('provider_id', $provider->id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Best-effort per-call audit row (v2.0 doc, 16.1/16.4) - never stores
     * the raw prompt/response payload (ai_provider_logs has no such
     * column by design), only status/timing/error metadata. Logging
     * failure never breaks the actual chat call.
     */
    protected function logCall(AiProvider $provider, ?AiRequest $context, float $startedAt, array $result): void
    {
        if (! $context) {
            return;
        }

        try {
            AiProviderLog::query()->create([
                'provider_id' => $provider->id,
                'request_id' => $context->id,
                'correlation_id' => $context->correlation_id,
                'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error_code' => $result['success'] ? null : 'provider_error',
                'error_message' => $result['success'] ? null : mb_substr((string) ($result['message'] ?? ''), 0, 500),
            ]);
        } catch (\Throwable) {
            // Audit logging must never take down a live chat request.
        }
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
