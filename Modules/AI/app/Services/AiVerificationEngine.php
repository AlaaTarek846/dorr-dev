<?php

namespace Modules\AI\Services;

use Illuminate\Support\Str;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRequest;

/**
 * Runs a single fact-checking pass over a draft chat answer, using a
 * second AI call (the "verifier") that inspects the draft against the
 * original question and returns a structured verdict - which claims it
 * makes, whether each one looks supported, how complete the answer is,
 * and any contradictions/gaps. From that verdict this computes a single
 * confidence_score, using an explicit weighted formula rather than
 * trusting a self-reported "I'm 95% sure" from the model (see v2.0
 * requirements doc, section 6: confidence must reflect actual verification
 * results, not be a fixed or self-rated number).
 *
 * This is a single verify() call only - AiChatService owns the retry /
 * regenerate / abstain loop that decides what to do with the result,
 * because that loop also needs to call back into the generator.
 */
class AiVerificationEngine
{
    public function __construct(protected AiGateway $gateway) {}

    /**
     * @return array{
     *     engine_error: bool,
     *     claims: list<array{claim: string, supported: bool, confidence: float, reason: ?string}>,
     *     issues: list<string>,
     *     supported_claims_ratio: ?float,
     *     completeness_score: ?float,
     *     evidence_strength: ?float,
     *     confidence_score: ?float,
     * }
     */
    public function verify(AiProvider $verifierProvider, string $question, string $draftAnswer, ?AiRequest $context = null): array
    {
        try {
            // AiGateway::chat() can throw synchronously for a verifier
            // provider whose `key` does not resolve to a known connector
            // (the same gap fixed in AiChatService::dispatchWithFallback())
            // - here that must degrade to the same graceful
            // engine_error result as any other verifier failure, not
            // crash the whole sendMessage() request from inside the
            // verification loop.
            $result = $this->gateway->chat($verifierProvider, $this->buildMessages($question, $draftAnswer), $context);
        } catch (\Throwable $e) {
            report($e);

            return $this->engineErrorResult();
        }

        if (! $result['success'] || blank($result['content'])) {
            return $this->engineErrorResult();
        }

        $parsed = $this->parseVerdict($result['content']);

        if ($parsed === null) {
            return $this->engineErrorResult();
        }

        return $this->score($parsed);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    protected function buildMessages(string $question, string $draftAnswer): array
    {
        $instructions = <<<'PROMPT'
You are a strict fact-checking verifier for an AI assistant's draft answer. You do not answer the user's question yourself - you only audit the draft that is given to you.

Break the draft answer down into its individual factual or actionable claims (skip pure pleasantries/greetings). For each claim, judge whether it is well-supported, reasonable and internally consistent, or whether it looks unsupported, invented, outdated, contradictory, or overconfident given what you can tell from the conversation alone.

Respond with ONLY a single JSON object, no markdown fences, no commentary, matching exactly this shape:
{
  "claims": [
    {"claim": "short restatement of the claim", "supported": true, "confidence": 0.9, "reason": "short reason"}
  ],
  "completeness": 0.9,
  "contradictions": ["any internal contradiction found, or omit if none"],
  "issues": ["any other problem: missing info, overconfident tone, unverifiable numbers, etc."]
}

"completeness" is how fully the draft answers the question (0 to 1). "confidence" per claim is your own confidence that the claim is accurate and safe to state as-is (0 to 1). If the draft has no checkable factual claims (e.g. it is a greeting, a clarifying question, or pure opinion/creative content), return an empty "claims" array and set "completeness" based on whether it reasonably addresses the user's message.
PROMPT;

        return [
            ['role' => 'system', 'content' => $instructions],
            ['role' => 'user', 'content' => "User's original message:\n{$question}\n\nDraft answer to audit:\n{$draftAnswer}\n\nReturn the JSON verdict now."],
        ];
    }

    /**
     * @return ?array{claims: array, completeness: ?float, contradictions: array, issues: array}
     */
    protected function parseVerdict(string $raw): ?array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?/i', '', $cleaned);
        $cleaned = preg_replace('/```$/', '', trim($cleaned));
        $cleaned = trim($cleaned);

        // The model sometimes wraps the JSON in a sentence despite
        // instructions - fall back to the first {...} block found.
        if (! Str::startsWith($cleaned, '{')) {
            if (! preg_match('/\{.*\}/s', $cleaned, $matches)) {
                return null;
            }

            $cleaned = $matches[0];
        }

        $decoded = json_decode($cleaned, true);

        if (! is_array($decoded)) {
            return null;
        }

        return [
            'claims' => is_array($decoded['claims'] ?? null) ? $decoded['claims'] : [],
            'completeness' => is_numeric($decoded['completeness'] ?? null) ? (float) $decoded['completeness'] : null,
            'contradictions' => is_array($decoded['contradictions'] ?? null) ? $decoded['contradictions'] : [],
            'issues' => is_array($decoded['issues'] ?? null) ? $decoded['issues'] : [],
        ];
    }

    /**
     * @param  array{claims: array, completeness: ?float, contradictions: array, issues: array}  $parsed
     * @return array{engine_error: bool, claims: array, issues: array, supported_claims_ratio: float, completeness_score: float, evidence_strength: float, confidence_score: float}
     */
    protected function score(array $parsed): array
    {
        $claims = collect($parsed['claims'])
            ->map(fn ($claim) => [
                'claim' => (string) ($claim['claim'] ?? ''),
                'supported' => (bool) ($claim['supported'] ?? false),
                'confidence' => is_numeric($claim['confidence'] ?? null) ? max(0.0, min(1.0, (float) $claim['confidence'])) : 0.0,
                'reason' => filled($claim['reason'] ?? null) ? (string) $claim['reason'] : null,
            ])
            ->filter(fn (array $claim) => $claim['claim'] !== '')
            ->values();

        $totalClaims = $claims->count();

        // No checkable claims (a greeting, a clarifying question, creative
        // writing) - nothing to be "wrong" about, so support/evidence are
        // treated as fully satisfied and confidence rests on completeness.
        $supportedRatio = $totalClaims > 0
            ? $claims->where('supported', true)->count() / $totalClaims
            : 1.0;

        $evidenceStrength = $totalClaims > 0
            ? round($claims->avg('confidence'), 3)
            : 1.0;

        $completeness = $parsed['completeness'] !== null ? max(0.0, min(1.0, $parsed['completeness'])) : 0.7;

        $issues = collect($parsed['contradictions'])->merge($parsed['issues'])
            ->map(fn ($issue) => (string) $issue)
            ->filter(fn (string $issue) => $issue !== '')
            ->values()
            ->all();

        // Weighted, documented formula (not a fixed/self-rated number):
        // how many claims actually check out matters most, then how
        // confident the verifier was in those claims individually, then
        // how completely the draft addressed the question.
        $confidence = ($supportedRatio * 0.5) + ($evidenceStrength * 0.3) + ($completeness * 0.2);

        // Any internal contradiction is a hard signal, regardless of the
        // weighted average - cap confidence so it cannot pass silently.
        if (! empty($parsed['contradictions'])) {
            $confidence = min($confidence, 0.55);
        }

        return [
            'engine_error' => false,
            'claims' => $claims->all(),
            'issues' => $issues,
            'supported_claims_ratio' => round($supportedRatio, 3),
            'completeness_score' => round($completeness, 3),
            'evidence_strength' => round($evidenceStrength, 3),
            'confidence_score' => round(max(0.0, min(1.0, $confidence)), 3),
        ];
    }

    /**
     * @return array{engine_error: bool, claims: array, issues: array, supported_claims_ratio: ?float, completeness_score: ?float, evidence_strength: ?float, confidence_score: ?float}
     */
    protected function engineErrorResult(): array
    {
        return [
            'engine_error' => true,
            'claims' => [],
            'issues' => [],
            'supported_claims_ratio' => null,
            'completeness_score' => null,
            'evidence_strength' => null,
            'confidence_score' => null,
        ];
    }
}
