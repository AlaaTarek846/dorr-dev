<?php

namespace Modules\AI\Safety;

use Modules\AI\Models\AiProvider;
use Modules\AI\Services\AiGateway;

/**
 * SafetyPolicyEngine (spec 350–362): around every free answer DORR AI gives — in its own chat or
 * inside DORR Chat — and never left to the model:
 *
 *  1. The RiskClassifier classifies the request first.
 *  2. The rules for that class go to the model as instructions (general information with its
 *     source; no final ruling / fatwa / legal opinion / diagnosis / treatment for a personal case;
 *     no execution details or safety guarantee for structural design; "not tested" for production
 *     code; no claimed compatibility it isn't sure of).
 *  3. After the answer: the approved, fixed referral sentence and the opening disclaimer are added
 *     by the system (from the language files — the model never words them), "optional" phrasing
 *     around checking or consulting is removed (354, 361), and the model's own copy of the fixed
 *     sentence is dropped so it shows once.
 *
 * The result carries `safety` for the app's alert card inside the reply.
 */
class SafetyPolicyEngine
{
    /** Phrases that would make checking, a source or a referral optional (354, 361). */
    private const FLEXIBLE = [
        'عند الحاجة', 'عند اللزوم', 'إذا لزم الأمر', 'اذا لزم الامر', 'إن لزم الأمر', 'ان لزم الامر', 'حيث يلزم', 'عندما تكون متاحة', 'عندما يكون متاحًا', 'إن أمكن', 'إذا أمكن', 'اذا امكن', 'لو احتجت', 'لو لزم',
        'if needed', 'when needed', 'if necessary', 'where necessary', 'where applicable', 'when available', 'if available', 'if possible', 'if you want', 'as needed',
    ];

    /** Words that start the clause a flexible phrase must not soften. */
    private const ADVICE = ['استشر', 'استشير', 'راجع', 'تحقق', 'تأكد', 'اسأل', 'اعرض', 'المصدر', 'مراجعة', 'اختبار', 'consult', 'verify', 'check', 'review', 'test', 'ask', 'see a', 'source'];

    public function __construct(
        private readonly RiskClassifier $classifier,
        private readonly AiGateway $gateway,
    ) {}

    public function classify(string $request, ?AiProvider $provider): RiskAssessment
    {
        return $this->classifier->classify($request, $provider);
    }

    /**
     * Classify, instruct, ask, finish — one free answer under the rules.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{success: bool, message: string, text: ?string, safety: ?array<string, mixed>, risk: RiskAssessment}
     */
    public function answer(AiProvider $provider, array $messages, string $request): array
    {
        $risk = $this->classify($request, $provider);
        $result = $this->gateway->chat($provider, $this->instruct($messages, $risk));
        if (! $result['success'] || trim((string) $result['content']) === '') {
            return ['success' => false, 'message' => $result['message'], 'text' => null, 'safety' => null, 'risk' => $risk];
        }

        return ['success' => true, 'message' => $result['message']] + $this->finish((string) $result['content'], $risk) + ['risk' => $risk];
    }

    /**
     * The rules for this request, as one more system message after the first one.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    public function instruct(array $messages, RiskAssessment $risk): array
    {
        $rules = $this->instructions($risk);
        if ($rules === null) {
            return $messages;
        }
        $at = isset($messages[0]) && $messages[0]['role'] === 'system' ? 1 : 0;
        array_splice($messages, $at, 0, [['role' => 'system', 'content' => $rules]]);

        return $messages;
    }

    public function instructions(RiskAssessment $risk): ?string
    {
        $noOptional = 'Never write that checking a source, testing or consulting a professional is optional (no "if needed", "when available", "if possible" or anything meaning that). '
            .'Do not add any disclaimer or referral sentence yourself — the system adds the approved one.';

        return match (true) {
            $risk->isHighStakes() => 'This request is about '.$risk->domain.' (high stakes). Give general information only, and name the reliable source it comes from (the law or authority, a recognised medical body, a known scholarly reference). '
                .($risk->specific ? 'It is about the person\'s own situation: do NOT give a final ruling, a fatwa, a decisive legal opinion, a diagnosis or a treatment plan — explain the general picture and what to bring to a qualified professional. ' : '')
                .$noOptional,
            $risk->domain === RiskAssessment::ENGINEERING => 'This is a design request. Keep every dimension, unit and measurement exactly as given. '
                .($risk->specific ? 'It touches structural, electrical or plumbing elements: give a general design concept only — no final execution details and no safety guarantee. ' : '')
                .$noOptional,
            $risk->domain === RiskAssessment::CODE => 'Do not claim a library or version is compatible unless you are sure. '
                .($risk->specific ? 'This code is for production, money, security or real user data: say plainly that it has not been run or tested. ' : '')
                .$noOptional,
            default => null,
        };
    }

    /**
     * After the answer: no optional phrasing, the model's own copy of the fixed sentence dropped,
     * and the approved texts for the card.
     *
     * @return array{text: string, safety: ?array<string, mixed>}
     */
    public function finish(string $answer, RiskAssessment $risk): array
    {
        $text = trim($answer);
        $safety = $this->safety($risk);
        if ($risk->domain === null) {
            return ['text' => $text, 'safety' => null];
        }

        $text = $this->firm($text);
        foreach (array_filter([$safety['notice'] ?? null, $safety['disclaimer'] ?? null]) as $fixed) {
            $text = trim(str_replace($fixed, '', $text));
        }

        return ['text' => $text, 'safety' => $safety];
    }

    /**
     * The approved texts for this class (spec 350, 352, 358, 359), in the request's language.
     *
     * @return array{domain: string, specific: bool, disclaimer: ?string, notice: ?string}|null
     */
    public function safety(RiskAssessment $risk): ?array
    {
        if ($risk->domain === null || (! $risk->isHighStakes() && ! $risk->specific)) {
            return null;
        }

        return [
            'domain' => $risk->domain,
            'specific' => $risk->specific,
            'disclaimer' => $risk->isHighStakes() ? __('ai.safety.disclaimer') : null,
            'notice' => $risk->needsReferral() ? __('ai.safety.notice.'.$risk->domain) : null,
        ];
    }

    /**
     * For places that show plain text only (the AI chat on the web): the answer with the approved
     * texts under it.
     *
     * @param  array<string, mixed>|null  $safety
     */
    public static function withNotice(string $text, ?array $safety): string
    {
        $parts = array_filter([$text, $safety['notice'] ?? null, $safety['disclaimer'] ?? null]);

        return implode("\n\n", $parts);
    }

    /** Remove "if needed"-style softeners from clauses about checking, sources or consulting. */
    private function firm(string $text): string
    {
        $phrases = implode('|', array_map(fn ($p) => preg_quote($p, '/'), self::FLEXIBLE));
        $advice = implode('|', array_map(fn ($p) => preg_quote($p, '/'), self::ADVICE));

        // "Consult a lawyer if needed." → "Consult a lawyer." (and the phrase before the advice too).
        $text = (string) preg_replace('/(?<lead>(?:'.$advice.')[^.!?؟\n]*?)[\s،,]*\(?(?:'.$phrases.')\)?/iu', '$1', $text);
        $text = (string) preg_replace('/(?:'.$phrases.')[\s،,]+(?=(?:'.$advice.'))/iu', '', $text);

        return $text;
    }
}
