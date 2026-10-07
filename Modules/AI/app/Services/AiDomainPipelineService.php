<?php

namespace Modules\AI\Services;

use Illuminate\Support\Str;
use Modules\AI\Models\AiCodeExecution;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiDomainPolicy;
use Modules\AI\Models\AiRequest;

/**
 * v2.0 requirements doc §7-14: applies the domain-specific rules that sit
 * on top of the generic chat pipeline (routing/safety/verification/RAG
 * stay unchanged for every message). Kept deliberately separate from
 * AiChatService so each concern (triage gate, jurisdiction nudge, code
 * sandbox verification) can be read, tested and extended on its own.
 */
class AiDomainPipelineService
{
    /**
     * Health domain hard emergency keywords (§8.1: triage happens BEFORE
     * any general content). Deliberately narrow and literal rather than
     * a fuzzy classifier - a false negative here is safer to handle with
     * the model's own safety training than a false positive is to annoy
     * a user in a real emergency by refusing to chat at all.
     *
     * @var list<string>
     */
    protected array $emergencyKeywords = [
        'انتحار', 'هاقتل نفسي', 'مش عايز اعيش', 'جلطة', 'سكتة قلبية', 'نزيف شديد',
        'مش قادر اتنفس', 'ألم شديد في الصدر', 'فقدان وعي', 'تسمم',
        'suicide', 'kill myself', 'overdose', 'can\'t breathe', 'chest pain', 'heart attack',
        'severe bleeding', 'unconscious', 'poisoned', 'stroke',
    ];

    /**
     * A light heuristic for §7.1 ("تحديد الدولة والاختصاص قبل إجابة
     * قانونية حساسة"): if none of these appear, the model is instructed
     * to ask for the jurisdiction rather than answer as if one country's
     * law applies.
     *
     * @var list<string>
     */
    protected array $jurisdictionHints = [
        'مصر', 'السعودية', 'الإمارات', 'الكويت', 'قطر', 'البحرين', 'عمان', 'الأردن', 'العراق', 'المغرب',
        'egypt', 'saudi', 'uae', 'kuwait', 'qatar', 'bahrain', 'oman', 'jordan',
    ];

    public function __construct(
        protected AiDomainClassifier $classifier,
        protected AiSandboxRunner $sandboxRunner,
    ) {}

    public function resolve(string $content): ?AiDomainPolicy
    {
        if (! config('ai.domains.enabled', true)) {
            return null;
        }

        return $this->classifier->classify($content);
    }

    /**
     * §8.1: runs before any AI dispatch. Returns a ready-made safe reply
     * when the message matches an emergency pattern, so the domain never
     * lets a real crisis wait on a model round-trip.
     */
    public function triageReply(?AiDomainPolicy $policy, string $content): ?string
    {
        if (! $policy || ! $policy->requires_triage) {
            return null;
        }

        $lower = mb_strtolower($content);

        foreach ($this->emergencyKeywords as $keyword) {
            if (Str::contains($lower, mb_strtolower($keyword))) {
                return __('ai.health_triage_emergency_reply');
            }
        }

        return null;
    }

    /**
     * Builds the extra system-role instruction for this domain, appended
     * to the normal system prompt - separate from RAG evidence, which is
     * always injected as DATA (see AiChatService::evidenceSystemMessage).
     */
    public function systemGuidance(?AiDomainPolicy $policy, string $content): ?string
    {
        if (! $policy) {
            return null;
        }

        $lines = [];

        if ($policy->system_prompt_addition) {
            $lines[] = $policy->system_prompt_addition;
        }

        if ($policy->requires_jurisdiction && ! $this->mentionsJurisdiction($content)) {
            $lines[] = 'The user has not specified a country or legal jurisdiction. Before giving a substantive legal answer, ask which country/jurisdiction applies, and make clear that laws vary by country.';
        }

        if ($policy->requires_triage) {
            $lines[] = 'This is a health-related question that was not flagged as an emergency. Do not provide a definitive diagnosis. Encourage seeing a licensed doctor for anything serious, and be explicit about uncertainty.';
        }

        if ($policy->domain_key === AiDomainPolicy::DOMAIN_MARKETING) {
            $lines[] = 'Clearly separate creative/promotional suggestions from factual claims. Never invent statistics, results, or performance promises that were not provided by the user.';
        }

        if ($policy->domain_key === AiDomainPolicy::DOMAIN_GENERAL_INFO) {
            $lines[] = 'If the question depends on current/time-sensitive information you cannot verify, say so explicitly rather than presenting a guess as a current fact.';
        }

        if ($policy->disclaimer_text) {
            $lines[] = 'End your answer with this disclaimer, translated to the reply\'s language if needed: "'.$policy->disclaimer_text.'"';
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    protected function mentionsJurisdiction(string $content): bool
    {
        $lower = mb_strtolower($content);

        foreach ($this->jurisdictionHints as $hint) {
            if (Str::contains($lower, mb_strtolower($hint))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{language: string, code: string}|null
     */
    public function extractCodeBlock(string $content): ?array
    {
        if (! preg_match('/```([a-zA-Z]*)\n(.*?)```/s', $content, $matches)) {
            return null;
        }

        $language = $this->normalizeLanguage($matches[1] ?: 'php');
        $code = trim($matches[2]);

        if ($code === '' || ! $this->sandboxRunner->languageConfig($language)) {
            return null;
        }

        return ['language' => $language, 'code' => $code];
    }

    protected function normalizeLanguage(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return match ($raw) {
            'js', 'javascript', 'nodejs' => 'node',
            'py' => 'python',
            default => $raw,
        };
    }

    /**
     * §10.3: runs one real, isolated execution attempt of the candidate
     * code block and persists it. Never guesses - the returned
     * AiCodeExecution::isSuccessful() reflects an actual exit code.
     */
    public function runSandboxAttempt(
        string $language,
        string $code,
        ?AiRequest $request,
        ?AiConversation $conversation,
        int $attemptNumber,
    ): AiCodeExecution {
        return $this->sandboxRunner->execute($language, $code, $request, $conversation, $attemptNumber);
    }

    /**
     * §10.4: the follow-up user-role turn used to ask the model to fix
     * its own code using the REAL stderr from the failed sandbox run -
     * never a generic "try again" prompt.
     */
    public function correctionPrompt(AiCodeExecution $execution): string
    {
        return "The code you just gave me was executed in a real sandbox and failed.

"
            ."Exit code: ".($execution->exit_code ?? 'n/a')."
"
            ."stderr:
".Str::limit((string) $execution->stderr, 1500)."

"
            ."Please fix the code and reply again with the corrected version in a single fenced code block.";
    }

    /**
     * Turns a final AiCodeExecution into the honest, human-readable note
     * appended to the assistant's reply.
     */
    public function executionNote(AiCodeExecution $execution): string
    {
        if ($execution->status === AiCodeExecution::STATUS_UNAVAILABLE) {
            return "

".__('ai.sandbox_execution_unavailable');
        }

        if ($execution->isSuccessful()) {
            return "

".__('ai.sandbox_execution_success');
        }

        return "

".__('ai.sandbox_execution_failed', [
            'error' => Str::limit((string) $execution->stderr, 800),
        ]);
    }
}
