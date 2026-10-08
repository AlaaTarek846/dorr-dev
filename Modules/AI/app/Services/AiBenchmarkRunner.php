<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Modules\AI\Models\AiBenchmarkCase;
use Modules\AI\Models\AiBenchmarkResult;
use Modules\AI\Models\AiBenchmarkRun;
use Modules\AI\Models\AiDomainPolicy;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;

/**
 * v2.0 requirements doc §19: Benchmark DORR - an independent, repeatable
 * measurement pass over a bank of prompts (easy/hard/adversarial/
 * insufficient-info), scored against the metrics the platform's real
 * infrastructure can actually compute today.
 *
 * Deliberately NOT implemented here (disclosed, not silently skipped):
 * - Groundedness/citation-accuracy beyond "did a citation marker like
 *   [1] appear in the text" - judging whether a citation actually
 *   supports the claim needs either a human reviewer or a second LLM
 *   pass, neither of which this runner fabricates.
 * - Hallucination rate as its own metric - AiVerificationEngine already
 *   does real claim-by-claim verification against RAG evidence for live
 *   chat traffic (§6); wiring the benchmark through that same engine is
 *   a natural next step but is intentionally left as a documented
 *   follow-up rather than approximated here with a fake number.
 * - Per-token cost - no AiProvider pricing table exists in this project
 *   yet, so estimated_cost stays null instead of guessing a figure.
 * - §19.5's "95% achieved" certification - this class only ever records
 *   the raw computed pass rate; no code anywhere reads that number and
 *   announces a certification claim.
 *
 * Routing reuses the same AiRoutingEngine live chat traffic uses (so the
 * benchmark measures the system users actually get), scoped to the admin
 * who triggered the run when no specific provider is forced.
 */
class AiBenchmarkRunner
{
    public function __construct(
        protected AiRoutingEngine $routingEngine,
        protected AiProviderRepository $providers,
        protected AiGateway $gateway,
    ) {}

    public function run(Authenticatable $triggeredBy, ?int $providerId = null, ?string $domainKey = null): AiBenchmarkRun
    {
        $cases = AiBenchmarkCase::query()
            ->where('is_active', true)
            ->when($domainKey, fn ($query) => $query->where('domain_key', $domainKey))
            ->get();

        $forcedProvider = $providerId ? AiProvider::query()->find($providerId) : null;

        $run = AiBenchmarkRun::query()->create([
            'provider_id' => $forcedProvider?->id,
            'status' => AiBenchmarkRun::STATUS_RUNNING,
            'total_cases' => $cases->count(),
            'started_at' => now(),
        ]);

        $passed = 0;
        $abstained = 0;
        $resolvedModelKey = null;

        foreach ($cases as $case) {
            $outcome = $this->runCase($triggeredBy, $case, $forcedProvider);

            AiBenchmarkResult::query()->create([
                'run_id' => $run->id,
                'case_id' => $case->id,
                ...$outcome,
            ]);

            $passed += $outcome['passed'] ? 1 : 0;
            $abstained += $outcome['abstained'] ? 1 : 0;
            $resolvedModelKey ??= $outcome['_model_key'] ?? null;
        }

        $total = $cases->count();

        $run->update([
            'status' => AiBenchmarkRun::STATUS_COMPLETED,
            'passed_cases' => $passed,
            'abstained_cases' => $abstained,
            'pass_rate' => $total > 0 ? round(($passed / $total) * 100, 2) : null,
            'model_key' => $resolvedModelKey,
            'finished_at' => now(),
        ]);

        return $run->fresh('results.case');
    }

    /**
     * @return array{actual_response: ?string, abstained: bool, citation_present: bool, correctness_score: ?float, passed: bool, latency_ms: ?int, estimated_cost: ?float, failure_reason: ?string, _model_key: ?string}
     */
    protected function runCase(Authenticatable $triggeredBy, AiBenchmarkCase $case, ?AiProvider $forcedProvider): array
    {
        $provider = $forcedProvider;
        $modelKey = null;

        if (! $provider) {
            $routing = $this->routingEngine->resolve($triggeredBy, $case->prompt, null, $this->providers);
            $candidate = $routing['candidates'][0] ?? null;

            if (! $candidate) {
                return $this->failedOutcome(__('ai.no_active_provider'), null);
            }

            $provider = $candidate['provider'];
            $modelKey = $candidate['model_key'];
        }

        $messages = $this->buildMessages($case);

        $startedAt = microtime(true);

        try {
            // v2.0 requirements doc S20.3: one bad case must not abort
            // the whole benchmark run. AiGateway::chat() can throw
            // synchronously for a provider whose `key` does not resolve
            // to a known connector (the same gap fixed across the live
            // chat path) - here that has to fail just this one case,
            // not take down run() and every case still queued behind it.
            $result = $this->gateway->chat($provider, $messages);
        } catch (\Throwable $e) {
            report($e);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            return $this->failedOutcome($e->getMessage(), $latencyMs, $modelKey);
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! ($result['success'] ?? false)) {
            return $this->failedOutcome((string) ($result['message'] ?? 'provider_error'), $latencyMs, $modelKey);
        }

        $content = (string) ($result['content'] ?? '');
        $abstained = $this->looksLikeAbstention($content);
        $citationPresent = (bool) preg_match('/\[\d+\]/', $content);
        $correctness = $this->scoreCorrectness($content, $case->expected_answer_keywords);
        $passed = $this->decidePassed($case, $abstained, $correctness, $content);

        return [
            'actual_response' => Str::limit($content, 20000, ''),
            'abstained' => $abstained,
            'citation_present' => $citationPresent,
            'correctness_score' => $correctness,
            'passed' => $passed,
            'latency_ms' => $latencyMs,
            'estimated_cost' => null,
            'failure_reason' => null,
            '_model_key' => $modelKey,
        ];
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    protected function buildMessages(AiBenchmarkCase $case): array
    {
        $messages = [
            ['role' => 'system', 'content' => (string) config('ai.chat.system_prompt')],
        ];

        if ($case->domain_key) {
            $policy = AiDomainPolicy::query()
                ->where('domain_key', $case->domain_key)
                ->where('is_active', true)
                ->first();

            if ($policy?->system_prompt_addition) {
                $messages[] = ['role' => 'system', 'content' => $policy->system_prompt_addition];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $case->prompt];

        return $messages;
    }

    protected function looksLikeAbstention(string $content): bool
    {
        if (trim($content) === '') {
            return true;
        }

        $abstainPhrase = mb_strtolower(__('ai.verification_abstain_reply'));

        return $abstainPhrase !== '' && str_contains(mb_strtolower($content), $abstainPhrase);
    }

    /**
     * Lightweight keyword-overlap scoring - not a semantic judge. A case
     * with no expected_answer_keywords configured is left unscored
     * (null) rather than defaulted to a misleading 0 or 1.
     */
    protected function scoreCorrectness(string $content, ?array $expectedKeywords): ?float
    {
        if (! $expectedKeywords || count($expectedKeywords) === 0) {
            return null;
        }

        $normalizedContent = mb_strtolower($content);
        $matched = 0;

        foreach ($expectedKeywords as $keyword) {
            if ($keyword !== '' && str_contains($normalizedContent, mb_strtolower((string) $keyword))) {
                $matched++;
            }
        }

        return round($matched / count($expectedKeywords), 3);
    }

    protected function decidePassed(AiBenchmarkCase $case, bool $abstained, ?float $correctness, string $content): bool
    {
        return match ($case->expected_behavior) {
            AiBenchmarkCase::BEHAVIOR_ABSTAIN => $abstained,
            AiBenchmarkCase::BEHAVIOR_ASK_CLARIFICATION => ! $abstained && (str_contains($content, '؟') || str_contains($content, '?')),
            default => ! $abstained && ($correctness === null || $correctness >= 0.5),
        };
    }

    protected function failedOutcome(string $reason, ?int $latencyMs, ?string $modelKey = null): array
    {
        return [
            'actual_response' => null,
            'abstained' => false,
            'citation_present' => false,
            'correctness_score' => null,
            'passed' => false,
            'latency_ms' => $latencyMs,
            'estimated_cost' => null,
            'failure_reason' => Str::limit($reason, 1000, ''),
            '_model_key' => $modelKey,
        ];
    }
}
