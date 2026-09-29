<?php

namespace Modules\AI\Services;

use Modules\AI\Enums\AiProviderKey;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiProviderDataRule;
use Modules\AI\Models\AiProviderModel;
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
     * @param  bool  $useWebSearch  See AiConnector::sendChat()'s docblock -
     *   only ever true when the caller already confirmed $provider's
     *   model is registered with the "web_search" capability.
     * @return array{success: bool, message: string, content: ?string}
     */
    public function chat(AiProvider $provider, array $messages, ?AiRequest $context = null, bool $useWebSearch = false): array
    {
        $provider = $this->applyModelOverrides($provider);
        $messages = $this->applyDataRules($provider, $messages);
        $messages = $this->formatMultimodalContent($provider, $messages);

        $startedAt = microtime(true);
        $result = $this->connectorFor($provider)->sendChat($provider, $messages, $useWebSearch);
        $this->logCall($provider, $context, $startedAt, $result);

        return $result;
    }

    /**
     * Business gap fix: temperature/max_tokens used to only ever exist on
     * the provider row - shared by every model registered under it, even
     * though different models genuinely need different settings (a
     * reasoning-tagged model wanting far more max_tokens than a
     * lightweight chat model, or a coding model wanting a near-zero
     * temperature while the provider's general chat model stays more
     * creative). When the specific model this AiProvider row is pointed
     * at (see modelSupportsVision()'s docblock - dispatchWithFallback
     * overrides ->model per candidate before any of this runs) has its
     * own temperature and/or max_tokens set in the ai_provider_models
     * registry, that value wins over the provider-wide default for this
     * one call - only for whichever of the two the model actually
     * overrides, the other still falls back to the provider's own value.
     * Every real call path goes through this single method (chat
     * dispatch, self-correction retries, the benchmark runner, the
     * verification engine), so the override applies uniformly wherever
     * an AiProvider row is actually used to talk to a model - not just
     * the main chat flow. A provider with no matching registered row, or
     * a registered row with both fields left blank ("inherit"), is
     * returned untouched.
     */
    protected function applyModelOverrides(AiProvider $provider): AiProvider
    {
        if (! $provider->model) {
            return $provider;
        }

        $registered = AiProviderModel::query()
            ->where('provider_id', $provider->id)
            ->where('model_key', $provider->model)
            ->first(['temperature', 'max_tokens']);

        if (! $registered || ($registered->temperature === null && $registered->max_tokens === null)) {
            return $provider;
        }

        return tap(clone $provider, function (AiProvider $clone) use ($registered) {
            if ($registered->temperature !== null) {
                $clone->temperature = $registered->temperature;
            }

            if ($registered->max_tokens !== null) {
                $clone->max_tokens = $registered->max_tokens;
            }
        });
    }

    /**
     * Real image editing (Business gap fix - see AiChatService::tryHandleImageEdit()
     * for why this exists): resolves the connector and hands it raw image
     * bytes + an edit instruction, exactly the same choke-point pattern
     * as chat()/embed() above, so this call is auditable and PII-sanitized
     * consistently with every other real provider call.
     *
     * @return array{success: bool, message: string, image: ?array{base64: string, mime: string}}
     */
    public function editImage(AiProvider $provider, string $imageBytes, string $imageMime, string $prompt, ?AiRequest $context = null): array
    {
        $rule = $this->dataRuleFor($provider);
        $sanitizedPrompt = $prompt;

        if ($rule?->sanitize_pii) {
            $sanitizedPrompt = $this->sanitizer->redactPii($sanitizedPrompt);
        }

        if ($rule?->sanitize_secrets) {
            $sanitizedPrompt = $this->sanitizer->redactSecrets($sanitizedPrompt);
        }

        $startedAt = microtime(true);
        $result = $this->connectorFor($provider)->editImage($provider, (string) $provider->model, $imageBytes, $imageMime, $sanitizedPrompt);
        $this->logCall($provider, $context, $startedAt, $result);

        return $result;
    }

    /**
     * Real text-to-image generation (no source image) - same
     * choke-point/PII-sanitization/audit pattern as editImage() above,
     * used when AiChatService determines this is a "create something
     * brand new" request rather than an edit of an existing picture.
     *
     * @return array{success: bool, message: string, image: ?array{base64: string, mime: string}}
     */
    public function generateImage(AiProvider $provider, string $prompt, ?AiRequest $context = null): array
    {
        $rule = $this->dataRuleFor($provider);
        $sanitizedPrompt = $prompt;

        if ($rule?->sanitize_pii) {
            $sanitizedPrompt = $this->sanitizer->redactPii($sanitizedPrompt);
        }

        if ($rule?->sanitize_secrets) {
            $sanitizedPrompt = $this->sanitizer->redactSecrets($sanitizedPrompt);
        }

        $startedAt = microtime(true);
        $result = $this->connectorFor($provider)->generateImage($provider, (string) $provider->model, $sanitizedPrompt);
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
     * Real speech-to-text (Business gap fix - see
     * AiChatService::transcribeIncomingAudio() for why this exists):
     * resolves the connector and hands it the raw voice-message bytes,
     * same choke-point pattern as editImage()/generateImage() above -
     * auditable and consistent, though there is no outgoing text prompt
     * here to PII/secret-sanitize (the audio bytes are opaque to the
     * sanitizer; the resulting transcript still goes through the normal
     * applyDataRules() sanitization once it re-enters chat() as part of
     * the conversation).
     *
     * @return array{success: bool, message: string, text: ?string}
     */
    public function transcribeAudio(AiProvider $provider, string $audioBytes, string $audioMime, ?AiRequest $context = null): array
    {
        $startedAt = microtime(true);
        $result = $this->connectorFor($provider)->transcribeAudio($provider, (string) $provider->model, $audioBytes, $audioMime);
        $this->logCall($provider, $context, $startedAt, $result);

        return $result;
    }

    /**
     * Real text-to-speech (Business gap fix - see
     * AiChatService::generateVoiceReplyAttachment() for why this exists):
     * same choke-point/PII-sanitization/audit pattern as editImage()/
     * generateImage() above, sanitizing the assistant's own reply text
     * before it goes out to the TTS provider exactly like a user prompt
     * would be.
     *
     * @return array{success: bool, message: string, audio: ?array{base64: string, mime: string}}
     */
    public function synthesizeSpeech(AiProvider $provider, string $text, ?AiRequest $context = null): array
    {
        $rule = $this->dataRuleFor($provider);

        if ($rule?->sanitize_pii) {
            $text = $this->sanitizer->redactPii($text);
        }

        if ($rule?->sanitize_secrets) {
            $text = $this->sanitizer->redactSecrets($text);
        }

        $startedAt = microtime(true);
        $result = $this->connectorFor($provider)->synthesizeSpeech($provider, (string) $provider->model, $text);
        $this->logCall($provider, $context, $startedAt, $result);

        return $result;
    }

    /**
     * Real Realtime session credential (Phase 7 - see AiRealtimeService
     * for why this exists): same choke-point/audit pattern as
     * transcribeAudio()/synthesizeSpeech() above - no PII sanitization
     * here, this call carries no user-authored text at all, only which
     * model/voice to mint a session for.
     *
     * @param  array{voice?: string, instructions?: ?string}  $options
     * @return array{success: bool, message: string, session: ?array{client_secret: string, expires_at: ?int, model: string}}
     */
    public function createRealtimeSession(AiProvider $provider, string $modelKey, array $options = [], ?AiRequest $context = null): array
    {
        $callProvider = tap(clone $provider, fn (AiProvider $p) => $p->model = $modelKey);

        $startedAt = microtime(true);
        $result = $this->connectorFor($callProvider)->createRealtimeSession($callProvider, $modelKey, $options);
        $this->logCall($callProvider, $context, $startedAt, $result);

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
            $isMultimodalMarker = is_array($content) && array_key_exists('text', $content);
            $text = $isMultimodalMarker ? $content['text'] : $content;

            if ($rule->sanitize_pii) {
                $text = $this->sanitizer->redactPii($text);
            }

            if ($rule->sanitize_secrets) {
                $text = $this->sanitizer->redactSecrets($text);
            }

            if ($isMultimodalMarker) {
                $content['text'] = $text;
            } else {
                $content = $text;
            }

            return ['role' => $message['role'], 'content' => $content];
        }, $messages);
    }

    /**
     * Turns the internal {"text": ..., "image": {"mime": ..., "base64": ...}}
     * marker AiChatService::buildHistory() builds for the current turn's
     * message (when an image is attached and routing picked a
     * vision-capable model) into whichever shape the target provider's
     * chat API actually expects - or, if the model this specific attempt
     * ended up using does NOT have the "vision" capability tagged in
     * ai_provider_models (e.g. a non-vision fallback provider), degrades
     * it back to the old plain-text attachment note instead of sending a
     * payload the provider cannot understand.
     *
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return list<array{role: string, content: mixed}>
     */
    protected function formatMultimodalContent(AiProvider $provider, array $messages): array
    {
        $supportsVision = $this->modelSupportsVision($provider);

        return array_map(function (array $message) use ($provider, $supportsVision) {
            $content = $message['content'];

            if (! is_array($content) || ! array_key_exists('image', $content)) {
                return $message;
            }

            $text = (string) ($content['text'] ?? '');

            if (! $supportsVision || ! is_array($content['image'] ?? null)) {
                $note = $content['image']['file_name'] ?? null;

                return ['role' => $message['role'], 'content' => $note
                    ? $text."

[".__('ai.attachment_note', ['name' => $note, 'type' => $content['image']['mime'] ?? ''])."]"
                    : $text];
            }

            $mime = (string) $content['image']['mime'];
            $base64 = (string) $content['image']['base64'];

            $formatted = match ($provider->key) {
                'openai', 'groq' => [
                    ['type' => 'text', 'text' => $text],
                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$base64}"]],
                ],
                'anthropic' => [
                    ['type' => 'text', 'text' => $text],
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]],
                ],
                'google' => [
                    ['text' => $text],
                    ['inline_data' => ['mime_type' => $mime, 'data' => $base64]],
                ],
                default => $text,
            };

            return ['role' => $message['role'], 'content' => $formatted];
        }, $messages);
    }

    /**
     * Whether the specific model this AiProvider row is currently pointed
     * at (dispatchWithFallback clones the provider and overrides ->model
     * per candidate attempt before calling here) is tagged "vision" in
     * the ai_provider_models registry. False - not just "unknown" - for a
     * provider with no registered models at all, so an unregistered
     * legacy $model column never silently gets treated as vision-capable.
     */
    protected function modelSupportsVision(AiProvider $provider): bool
    {
        if (! $provider->model) {
            return false;
        }

        $capabilities = AiProviderModel::query()
            ->where('provider_id', $provider->id)
            ->where('model_key', $provider->model)
            ->value('capabilities');

        return is_array($capabilities) && in_array('vision', $capabilities, true);
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
