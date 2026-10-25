<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Modules\AI\Enums\AiModelCapability;
use Modules\AI\Models\AiDataPolicy;
use Modules\AI\Models\AiFailover;
use Modules\AI\Models\AiMediaGeneration;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRequest;
use Modules\AI\Models\AiResponse;
use Modules\AI\Models\AiSafetyEvent;
use Modules\AI\Models\AiSafetyRule;
use Modules\AI\Models\AiTrialControl;
use Modules\AI\Models\AiUsage;
use Modules\AI\Repositories\AiProviderRepository;

/**
 * One AI task for another module (the chat's translate, summary, smart replies, voice to text,
 * Discover's search…) run through the same system as the DORR AI chat, so the admin's settings
 * apply to every AI call on the platform, not only to the DORR AI chat:
 *
 * - an owner the admin blocked from AI (ai_trial_control) gets nothing;
 * - the admin's safety rules (AiSafetyGuard) block, sanitize or flag the person's text;
 * - routing (AiRoutingEngine) picks the provider and model — routing policies, per-country
 *   provider/model flags, per-intent rules — with the same fallback and circuit breaker;
 * - each call is an ai_requests row (with `source`), plus its response, estimated usage and
 *   provider log, so it shows on the admin usage screens;
 * - voice to text uses the speech-to-text model registered in the Model Registry;
 * - images (generate / edit) use the image model registered in the Model Registry and the plan's
 *   daily image allowance (AiMediaQuotaService), exactly like images in the DORR AI chat.
 *
 * The AI plans apply (decision 2026-10-15): every task for a person needs what the DORR AI chat
 * needs — an active plan or the free trial the admin offers, within its usage window and cooldown
 * (AiChatUsageGuard) — and uses up that window the same way. `$free` exempts a task the platform
 * runs for the person rather than one they ask for (support's automatic FAQ answer).
 *
 * Never throws for a provider problem: the result says what happened (`reason`), and the caller
 * turns it into its own error.
 */
class AiTaskRunner
{
    public const REASON_UNAVAILABLE = 'unavailable';

    public const REASON_OWNER_BLOCKED = 'owner_blocked';

    public const REASON_CONTENT_BLOCKED = 'content_blocked';

    public const REASON_FAILED = 'failed';

    /** No plan that allows it now (no plan, trial ended, cooldown, limit, suspended) — `detail.usage_reason` says which. */
    public const REASON_PLAN = 'plan_required';

    /** The plan has no images, or today's images are used up — `detail.limit`. */
    public const REASON_IMAGE_NOT_IN_PLAN = 'image_not_in_plan';

    public const REASON_IMAGE_LIMIT = 'image_daily_limit';

    public function __construct(
        protected AiProviderRepository $providers,
        protected AiGateway $gateway,
        protected AiRoutingEngine $routingEngine,
        protected AiSafetyGuard $safetyGuard,
        protected AiCircuitBreaker $circuitBreaker,
        protected AiAuditTrail $auditTrail,
        protected AiPiiSanitizer $piiSanitizer,
        protected AiModelResolver $modelResolver,
        protected AiChatLanguageResolver $languageResolver,
        protected AiChatUsageGuard $usageGuard,
        protected AiMediaQuotaService $mediaQuota,
    ) {}

    /**
     * Today's images on the person's plan, read-only (starts nothing): whether the plan allows AI at
     * all, and the daily allowance — `limit` null means unlimited, 0 means the plan has no images.
     *
     * @return array{allowed: bool, reason: ?string, limit: ?int, used: int, remaining: ?int}
     */
    public function imageAllowance(Authenticatable $owner): array
    {
        $plan = $this->entitlement($owner);
        $used = $this->mediaQuota->usedToday($owner, AiMediaGeneration::KIND_IMAGE);
        if (! $plan['allowed']) {
            return ['allowed' => false, 'reason' => $plan['reason'], 'limit' => null, 'used' => $used, 'remaining' => 0];
        }

        $check = $this->mediaQuota->check($owner, $this->usageGuard->currentPlan($owner), AiMediaGeneration::KIND_IMAGE);

        return ['allowed' => $check['allowed'], 'reason' => $check['reason'], 'limit' => $check['limit'], 'used' => $check['used'], 'remaining' => $check['remaining']];
    }

    /** Whether images can be made at all (an image model is registered on a usable provider). */
    public function canMakeImages(): bool
    {
        return $this->imageCandidate() !== null;
    }

    /**
     * Whether the person's plan allows AI right now, read-only (for showing or locking buttons).
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public function entitlement(Authenticatable $owner): array
    {
        return $this->ownerBlocked($owner) ? ['allowed' => false, 'reason' => AiChatUsageGuard::REASON_BLOCKED] : $this->usageGuard->preview($owner);
    }

    /** Whether there is anything to answer a text task with. */
    public function available(): bool
    {
        return $this->providers->hasAnyUsableProvider();
    }

    /** Whether voice can be turned into text (a registered speech-to-text model, or an audio-capable provider). */
    public function canTranscribe(): bool
    {
        return $this->speechToTextCandidate() !== null || $this->providers->resolveForTranscription() !== null;
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return array{success: bool, reason: ?string, content: ?string, message: ?string, provider: ?AiProvider, model: ?string}
     */
    public function chat(array $messages, string $source, ?Authenticatable $owner = null, bool $free = false): array
    {
        if ($this->ownerBlocked($owner)) {
            return $this->fail(self::REASON_OWNER_BLOCKED);
        }

        ['usage' => $usage, 'fail' => $refused] = $this->entitle($owner, $free);
        if ($refused !== null) {
            return $refused;
        }

        $index = $this->lastUserIndex($messages);
        $input = $index === null ? '' : $this->textOf($messages[$index]['content']);

        $safety = $this->safetyGuard->evaluate($input);
        if ($safety['action'] === AiSafetyRule::ACTION_BLOCK) {
            $this->logSafetyEvent($owner, $safety, $input);

            return $this->fail(self::REASON_CONTENT_BLOCKED, __('ai.message_blocked'));
        }
        if (in_array($safety['action'], [AiSafetyRule::ACTION_REVIEW, AiSafetyRule::ACTION_SANITIZE], true)) {
            $this->logSafetyEvent($owner, $safety, $input);
        }
        if ($index !== null && is_string($safety['sanitized'] ?? null) && is_string($messages[$index]['content'])) {
            $messages[$index]['content'] = $safety['sanitized'];
            $input = $safety['sanitized'];
        }

        [$candidates, $fallbackEnabled, $routing] = $this->candidates($owner, $input);
        if ($candidates === []) {
            return $this->fail(self::REASON_UNAVAILABLE);
        }

        $request = $this->openRequest($owner, $source, $candidates[0], $input, $routing);
        [$result, $provider, $model] = $this->dispatch($candidates, $fallbackEnabled, $messages, $request);
        $content = trim((string) ($result['content'] ?? ''));
        $success = $result['success'] && $content !== '';

        $this->closeRequest($request, $provider, $model, $success, $success ? $content : null, (string) ($result['message'] ?? ''), $input);

        if (! $success) {
            return $this->fail(self::REASON_FAILED, (string) ($result['message'] ?? ''), $provider, $model);
        }
        $this->consumed($usage);

        return ['success' => true, 'reason' => null, 'content' => $content, 'message' => null, 'provider' => $provider, 'model' => $model, 'detail' => []];
    }

    /**
     * A new image from a description (chat spec 50), with the image model registered in the Model
     * Registry, counted against the plan's daily images like the DORR AI chat's.
     *
     * @return array{success: bool, reason: ?string, image: ?array{base64: string, mime: string}, message: ?string, detail: array<string, mixed>}
     */
    public function generateImage(string $prompt, string $source, Authenticatable $owner): array
    {
        return $this->image($prompt, $source, $owner, null, null);
    }

    /**
     * An existing image changed as described (chat spec 51) — same model, plan and quota.
     *
     * @return array{success: bool, reason: ?string, image: ?array{base64: string, mime: string}, message: ?string, detail: array<string, mixed>}
     */
    public function editImage(string $imageBytes, string $imageMime, string $prompt, string $source, Authenticatable $owner): array
    {
        return $this->image($prompt, $source, $owner, $imageBytes, $imageMime);
    }

    /**
     * Voice to text with the speech-to-text model registered in the Model Registry (the same one
     * the DORR AI chat uses for voice messages); without one, any audio-capable provider.
     *
     * @return array{success: bool, reason: ?string, content: ?string, message: ?string, provider: ?AiProvider, model: ?string}
     */
    public function transcribe(string $path, string $mime, string $source, ?Authenticatable $owner = null): array
    {
        if ($this->ownerBlocked($owner)) {
            return $this->fail(self::REASON_OWNER_BLOCKED);
        }

        ['usage' => $usage, 'fail' => $refused] = $this->entitle($owner, false);
        if ($refused !== null) {
            return $refused;
        }

        $candidate = $this->speechToTextCandidate();
        $provider = $candidate['provider'] ?? $this->providers->resolveForTranscription();
        if ($provider === null) {
            return $this->fail(self::REASON_UNAVAILABLE);
        }

        $model = $candidate['model_key'] ?? $provider->model;
        $request = $this->openRequest($owner, $source, ['provider' => $provider, 'model_key' => $model], '['.$mime.']', null);

        if ($this->circuitBreaker->isOpen($provider)) {
            $result = ['success' => false, 'message' => __('ai.circuit_breaker_open'), 'content' => null];
        } else {
            try {
                if ($candidate !== null) {
                    $bytes = @file_get_contents($path);
                    $hint = $owner ? $this->languageResolver->preferredLanguageCode($owner) : null;
                    $call = tap(clone $provider, fn (AiProvider $p) => $p->model = $model);
                    $heard = $bytes === false
                        ? ['success' => false, 'message' => 'Audio file could not be read.', 'text' => null]
                        : $this->gateway->transcribeAudio($call, $bytes, $mime, $hint, $request);
                    $result = ['success' => $heard['success'], 'message' => $heard['message'] ?? '', 'content' => $heard['text'] ?? null];
                } else {
                    $result = $this->gateway->transcribe($provider, $path, $mime);
                }
            } catch (\Throwable $e) {
                report($e);
                $result = ['success' => false, 'message' => __('ai.provider_unavailable'), 'content' => null];
            }

            $result['success'] ? $this->circuitBreaker->recordSuccess($provider) : $this->circuitBreaker->recordFailure($provider);
        }

        $content = trim((string) ($result['content'] ?? ''));
        $success = $result['success'] && $content !== '';
        $this->closeRequest($request, $provider, $model, $success, $success ? $content : null, (string) ($result['message'] ?? ''), '');

        if (! $success) {
            return $this->fail(self::REASON_FAILED, (string) ($result['message'] ?? ''), $provider, $model);
        }
        $this->consumed($usage);

        return ['success' => true, 'reason' => null, 'content' => $content, 'message' => null, 'provider' => $provider, 'model' => $model, 'detail' => []];
    }

    /**
     * @return array{success: bool, reason: ?string, image: ?array{base64: string, mime: string}, message: ?string, detail: array<string, mixed>}
     */
    protected function image(string $prompt, string $source, Authenticatable $owner, ?string $sourceBytes, ?string $sourceMime): array
    {
        if ($this->ownerBlocked($owner)) {
            return $this->fail(self::REASON_OWNER_BLOCKED);
        }

        $safety = $this->safetyGuard->evaluate($prompt);
        if ($safety['action'] === AiSafetyRule::ACTION_BLOCK) {
            $this->logSafetyEvent($owner, $safety, $prompt);

            return $this->fail(self::REASON_CONTENT_BLOCKED, __('ai.message_blocked'));
        }
        if (in_array($safety['action'], [AiSafetyRule::ACTION_REVIEW, AiSafetyRule::ACTION_SANITIZE], true)) {
            $this->logSafetyEvent($owner, $safety, $prompt);
        }
        $prompt = is_string($safety['sanitized'] ?? null) ? $safety['sanitized'] : $prompt;

        ['usage' => $usage, 'fail' => $refused] = $this->entitle($owner, false);
        if ($refused !== null) {
            return $refused;
        }

        $candidate = $this->imageCandidate();
        if ($candidate === null) {
            return $this->fail(self::REASON_UNAVAILABLE);
        }
        $provider = $candidate['provider'];
        $call = tap(clone $provider, fn (AiProvider $p) => $p->model = $candidate['model_key']);
        $request = $this->openRequest($owner, $source, $candidate, $prompt, null);

        // The plan's daily images, reserved under a lock (the same ledger the DORR AI chat counts).
        $reserved = $this->mediaQuota->reserve($owner, $usage['plan'] ?? null, AiMediaGeneration::KIND_IMAGE, [
            'request_id' => $request?->id,
            'provider_id' => $provider->id,
            'model_key' => $candidate['model_key'],
            'prompt' => Str::limit($this->piiSanitizer->redactBoth($prompt), 2000, ''),
        ]);
        if ($reserved['generation'] === null) {
            $notInPlan = $reserved['decision']['reason'] === AiMediaQuotaService::REASON_NOT_IN_PLAN;
            $this->closeRequest($request, $provider, $candidate['model_key'], false, null, 'image_quota:'.$reserved['decision']['reason'], $prompt);

            return $this->fail($notInPlan ? self::REASON_IMAGE_NOT_IN_PLAN : self::REASON_IMAGE_LIMIT, null, $provider, $candidate['model_key'], ['limit' => (int) ($reserved['decision']['limit'] ?? 0)]);
        }

        if ($this->circuitBreaker->isOpen($provider)) {
            $result = ['success' => false, 'message' => __('ai.circuit_breaker_open'), 'image' => null];
        } else {
            $attempt = fn () => $sourceBytes === null
                ? $this->gateway->generateImage($call, $prompt, $request)
                : $this->gateway->editImage($call, $sourceBytes, (string) $sourceMime, $prompt, $request);
            try {
                // One retry, like the DORR AI chat's image calls (a provider hiccup is common here).
                $result = $attempt();
                if (! $result['success']) {
                    $result = $attempt();
                }
            } catch (\Throwable $e) {
                report($e);
                $result = ['success' => false, 'message' => __('ai.provider_unavailable'), 'image' => null];
            }
            $result['success'] ? $this->circuitBreaker->recordSuccess($provider) : $this->circuitBreaker->recordFailure($provider);
        }

        $success = $result['success'] && ! empty($result['image']['base64'] ?? null);
        $success ? $this->mediaQuota->markCompleted($reserved['generation']) : $this->mediaQuota->markFailed($reserved['generation'], 'provider_failed', (string) ($result['message'] ?? ''));
        $this->closeRequest($request, $provider, $candidate['model_key'], $success, $success ? '[image]' : null, (string) ($result['message'] ?? ''), $prompt);

        if (! $success) {
            return $this->fail(self::REASON_FAILED, (string) ($result['message'] ?? ''), $provider, $candidate['model_key']);
        }
        $this->consumed($usage);

        return ['success' => true, 'reason' => null, 'image' => $result['image'], 'message' => null, 'provider' => $provider, 'model' => $candidate['model_key'], 'detail' => []];
    }

    /**
     * The person's plan, the same check the DORR AI chat makes before every message (it starts the
     * free trial or a usage window when due). Nothing to check with no owner, or for a free task.
     *
     * @return array{usage: ?array<string, mixed>, fail: ?array<string, mixed>}
     */
    protected function entitle(?Authenticatable $owner, bool $free): array
    {
        if ($owner === null || $free) {
            return ['usage' => null, 'fail' => null];
        }

        $usage = $this->usageGuard->evaluate($owner);

        return $usage['allowed']
            ? ['usage' => $usage, 'fail' => null]
            : ['usage' => $usage, 'fail' => $this->fail(self::REASON_PLAN, null, null, null, ['usage_reason' => $usage['reason'], 'cooldown_seconds_left' => $usage['cooldown_seconds_left']])];
    }

    /** The task used the plan's time, like a DORR AI chat message does. */
    protected function consumed(?array $usage): void
    {
        if (($usage['session'] ?? null) !== null) {
            $this->usageGuard->recordConsumption($usage['session']);
        }
    }

    /** @return array{provider: AiProvider, model_key: string}|null */
    protected function imageCandidate(): ?array
    {
        $match = $this->modelResolver->resolve($this->providers, [AiModelCapability::ImageGeneration->value]);

        return $match !== null ? ['provider' => $match['provider'], 'model_key' => $match['model']->model_key] : null;
    }

    /**
     * The providers to try, in order: the routing engine's candidates for this owner and text,
     * or — with no owner to route for (a background job) — the default chat provider.
     *
     * @return array{0: list<array{provider: AiProvider, model_key: ?string}>, 1: bool, 2: ?array<string, mixed>}
     */
    protected function candidates(?Authenticatable $owner, string $input): array
    {
        if ($owner === null) {
            $provider = $this->providers->resolveActiveForChat();

            return [$provider ? [['provider' => $provider, 'model_key' => null]] : [], false, null];
        }

        $routing = $this->routingEngine->resolve($owner, $input, null, $this->providers);

        return [array_values($routing['candidates']), (bool) $routing['fallback_enabled'], $routing];
    }

    /**
     * Same loop as the DORR AI chat (AiChatService::dispatchWithFallback()): an open circuit is
     * skipped, a failure moves on to the next candidate when the policy allows it, and each
     * hand-over is recorded.
     *
     * @param  list<array{provider: AiProvider, model_key: ?string}>  $candidates
     * @return array{0: array{success: bool, message: string, content: ?string}, 1: AiProvider, 2: ?string}
     */
    protected function dispatch(array $candidates, bool $fallbackEnabled, array $messages, ?AiRequest $request): array
    {
        $last = [['success' => false, 'message' => __('ai.provider_unavailable'), 'content' => null], $candidates[0]['provider'], null];

        foreach ($candidates as $index => $candidate) {
            $provider = $candidate['provider'];
            $call = $candidate['model_key'] ? tap(clone $provider, fn (AiProvider $p) => $p->model = $candidate['model_key']) : $provider;
            $circuitOpen = $this->circuitBreaker->isOpen($provider);

            if ($circuitOpen) {
                $result = ['success' => false, 'message' => __('ai.circuit_breaker_open'), 'content' => null];
                $this->auditTrail->record(
                    AiAuditTrail::EVENT_CIRCUIT_BREAKER_OPENED,
                    severity: 'high',
                    subjectType: 'ai_provider',
                    subjectId: $provider->id,
                    traceId: $request?->correlation_id,
                    metadata: ['provider_key' => $provider->key],
                );
            } else {
                try {
                    $result = $this->gateway->chat($call, $messages, $request);
                } catch (\Throwable $e) {
                    report($e);
                    $result = ['success' => false, 'message' => __('ai.provider_unavailable'), 'content' => null];
                }
                $ok = $result['success'] && trim((string) ($result['content'] ?? '')) !== '';
                $ok ? $this->circuitBreaker->recordSuccess($provider) : $this->circuitBreaker->recordFailure($provider);
            }

            $last = [$result, $provider, $call->model];
            if ($result['success'] && trim((string) ($result['content'] ?? '')) !== '') {
                return $last;
            }

            if (! $fallbackEnabled || ! isset($candidates[$index + 1])) {
                break;
            }
            if ($request !== null) {
                AiFailover::query()->create([
                    'primary_provider_id' => $provider->id,
                    'fallback_provider_id' => $candidates[$index + 1]['provider']->id,
                    'request_id' => $request->id,
                    'trigger_type' => $circuitOpen ? AiFailover::TRIGGER_HEALTH_THRESHOLD : AiFailover::TRIGGER_PROVIDER_ERROR,
                    'attempt_number' => $index + 1,
                    'reason' => Str::limit((string) ($result['message'] ?? ''), 500),
                ]);
            }
        }

        return $last;
    }

    /**
     * @param  array{provider: AiProvider, model_key: ?string}  $first
     * @param  ?array<string, mixed>  $routing
     */
    protected function openRequest(?Authenticatable $owner, string $source, array $first, string $input, ?array $routing): ?AiRequest
    {
        if ($owner === null) {
            return null;
        }

        try {
            return AiRequest::query()->create([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getAuthIdentifier(),
                'gateway_id' => $routing['gateway']?->id ?? null,
                'intent_id' => $routing['intent']?->id ?? null,
                'source' => $source,
                'provider_id' => $first['provider']->id,
                'model_key' => $first['model_key'] ?? $first['provider']->model,
                'prompt' => $this->minimizedForLog($input),
                'correlation_id' => (string) Str::uuid(),
                'status' => AiRequest::STATUS_PROCESSING,
            ]);
        } catch (\Throwable $e) {
            // The audit row must never stop the task itself.
            report($e);

            return null;
        }
    }

    protected function closeRequest(?AiRequest $request, AiProvider $provider, ?string $model, bool $success, ?string $content, string $error, string $input): void
    {
        if ($request === null) {
            return;
        }

        try {
            $request->provider_id = $provider->id;
            $request->model_key = $model;
            $request->status = $success ? AiRequest::STATUS_COMPLETED : AiRequest::STATUS_FAILED;
            $request->error_message = $success ? null : Str::limit($error, 500);
            $request->save();

            AiResponse::query()->create([
                'request_id' => $request->id,
                'response' => ['content' => $content === null ? null : $this->minimizedForLog($content), 'message' => $success ? null : $error],
                'finish_reason' => $success ? AiResponse::FINISH_COMPLETED : AiResponse::FINISH_ERROR,
            ]);

            // Rough estimate (~4 chars a token), flagged as such — same as the DORR AI chat.
            $in = (int) ceil(mb_strlen($input) / 4);
            $out = $success ? (int) ceil(mb_strlen((string) $content) / 4) : 0;
            AiUsage::query()->create([
                'request_id' => $request->id,
                'input_tokens' => $in,
                'output_tokens' => $out,
                'total_tokens' => $in + $out,
                'usage_type' => AiUsage::TYPE_ESTIMATED,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Blocked from AI by the admin (abuse control) — the same flag the DORR AI chat checks. */
    protected function ownerBlocked(?Authenticatable $owner): bool
    {
        return $owner !== null && AiTrialControl::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->where('abuse_status', AiTrialControl::ABUSE_BLOCKED)
            ->exists();
    }

    /** @return array{provider: AiProvider, model_key: string}|null */
    protected function speechToTextCandidate(): ?array
    {
        $match = $this->modelResolver->resolve($this->providers, [AiModelCapability::SpeechToText->value]);

        return $match !== null ? ['provider' => $match['provider'], 'model_key' => $match['model']->model_key] : null;
    }

    /** @param  array<string, mixed>  $safety */
    protected function logSafetyEvent(?Authenticatable $owner, array $safety, string $content): void
    {
        if (! $safety['rule']) {
            return;
        }

        if ($owner !== null) {
            AiSafetyEvent::query()->create([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getAuthIdentifier(),
                'safety_policy_id' => $safety['rule']->safety_policy_id,
                'safety_rule_id' => $safety['rule']->id,
                'action_taken' => $safety['action'],
                'reason' => Str::limit($content, 500),
            ]);
        }

        $this->auditTrail->record(
            AiAuditTrail::EVENT_SAFETY_RULE_TRIGGERED,
            owner: $owner,
            severity: 'medium',
            subjectType: 'ai_safety_rule',
            subjectId: $safety['rule']->id,
            metadata: ['action_taken' => $safety['action']],
        );
    }

    /** Personal data and secrets out of the logs, unless the admin's data policy turned minimization off. */
    protected function minimizedForLog(string $text): string
    {
        $policy = AiDataPolicy::query()
            ->where('data_classification', AiDataPolicy::CLASSIFICATION_PERSONAL)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        return $policy && ! $policy->minimization_enabled ? $text : $this->piiSanitizer->redactBoth($text);
    }

    /** @param  list<array{role: string, content: mixed}>  $messages */
    protected function lastUserIndex(array $messages): ?int
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? null) === 'user') {
                return $i;
            }
        }

        return null;
    }

    protected function textOf(mixed $content): string
    {
        return is_string($content) ? $content : (is_array($content) ? (string) ($content['text'] ?? '') : '');
    }

    /**
     * @param  array<string, mixed>  $detail  usage_reason / cooldown_seconds_left (plan), limit (images)
     * @return array{success: bool, reason: string, content: null, image: null, message: ?string, provider: ?AiProvider, model: ?string, detail: array<string, mixed>}
     */
    protected function fail(string $reason, ?string $message = null, ?AiProvider $provider = null, ?string $model = null, array $detail = []): array
    {
        return ['success' => false, 'reason' => $reason, 'content' => null, 'image' => null, 'message' => $message, 'provider' => $provider, 'model' => $model, 'detail' => $detail];
    }
}
