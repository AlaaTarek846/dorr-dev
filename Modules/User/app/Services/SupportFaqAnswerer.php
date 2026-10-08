<?php

namespace Modules\User\Services;

use App\Models\Faq;
use App\Repositories\General\FaqRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Safety\RiskClassifier;
use Modules\AI\Services\AiGateway;
use Throwable;

/**
 * Answers a support ticket from the FAQs — through the AI module, exactly like DORR Chat's AI does:
 * the default chat provider (`AiProviderRepository::resolveActiveForChat`), one call through
 * `AiGateway::chat` (which applies the providers' data rules, strips personal data and logs the call),
 * and the AI safety layer's fixed rules (`RiskClassifier::byRules`, no extra model call).
 *
 * It never invents: the model may only restate one of the published FAQs, chosen by its id, and says
 * "none" when none of them answers. And some tickets never get an automatic answer at all — they go
 * straight to a person (see needsAPerson): money that went missing, a charge, fraud, a complaint, a
 * legal / medical / religious question, or simply someone asking for a human.
 */
class SupportFaqAnswerer
{
    /** How much of the FAQs and of the ticket the model reads. */
    private const MAX_FAQS = 40;

    private const MAX_ANSWER_CHARS = 700;

    private const MAX_QUESTION_CHARS = 1500;

    /**
     * Words that mean "a person must look at this" (Arabic, Egyptian and English). Phrases are matched
     * whole; single words on word boundaries.
     */
    private const NEEDS_A_PERSON = [
        // money that moved (or did not) — a problem with a payment, not a "how do I…" about the wallet
        'refund', 'refunded', 'charged', 'charge back', 'chargeback', 'deducted', 'double charge',
        'transfer failed', 'wrong transfer', 'not received', 'missing money', 'my money',
        'استرجاع', 'استرداد', 'اتخصم', 'اتسحب', 'خصموا', 'فلوسي', 'تحويل غلط', 'تحويل خاطئ', 'ما وصل', 'موصلش', 'موصلتش',
        // fraud, theft, a hacked account
        'fraud', 'scam', 'stolen', 'hacked', 'unauthorized', 'نصب', 'احتيال', 'نصاب', 'سرقة', 'اتسرق', 'مسروق', 'اختراق', 'مخترق', 'اتهكر',
        // complaints, legal action
        'complaint', 'lawyer', 'lawsuit', 'police', 'report you', 'شكوى', 'محامي', 'قضية', 'بلاغ', 'الشرطة', 'هشتكي', 'سأشتكي',
        // asking for a person
        'human', 'real person', 'agent', 'manager', 'موظف', 'شخص حقيقي', 'مدير', 'كلموني', 'اتصلوا بي', 'عايز اكلم',
    ];

    public function __construct(
        private readonly AiProviderRepository $providers,
        private readonly AiGateway $gateway,
        private readonly RiskClassifier $risk,
        private readonly FaqRepository $faqs,
    ) {}

    /**
     * The ticket is something a person has to handle — no automatic answer, whatever the FAQs say.
     */
    public function needsAPerson(string $text): bool
    {
        // The AI safety layer's own rules: religion, law, medicine are never answered by a bot here.
        if ($this->risk->byRules($text)->isHighStakes()) {
            return true;
        }

        $haystack = ' '.mb_strtolower($text).' ';

        foreach (self::NEEDS_A_PERSON as $word) {
            $needle = mb_strtolower($word);

            if (str_contains($needle, ' ') ? str_contains($haystack, $needle) : preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * An answer taken from one FAQ, in the customer's language, or null (no provider, no FAQ that
     * answers it, a sensitive ticket, or the provider failed — the ticket then just waits for a person).
     *
     * @return array{answer: string, faq_id: int}|null
     */
    public function answer(string $question, string $locale): ?array
    {
        $question = trim($question);

        if ($question === '' || $this->needsAPerson($question)) {
            return null;
        }

        $provider = $this->providers->resolveActiveForChat();
        $faqs = $this->catalog($locale);

        if ($provider === null || $faqs === []) {
            return null;
        }

        $language = $locale === 'ar' ? 'Arabic' : 'English';

        try {
            $result = $this->gateway->chat($provider, [
                ['role' => 'system', 'content' => "You are the automatic first reply of Dorr's customer support. You may ONLY use the FAQ entries given below — never your own knowledge, never invent steps, prices, times or promises (no refunds, no compensation, no deadlines).\n"
                    ."Decide whether exactly one FAQ entry clearly answers the customer's ticket. If it does, rewrite that entry's answer as a short, friendly reply to this customer in {$language} (at most 5 sentences, no greeting longer than a few words, no markdown). If none clearly answers it, or the ticket is about money, a payment, fraud, a complaint or a request for a person, answer none.\n"
                    .'Reply with JSON only: {"faq_id": <the id of the entry you used, or null>, "answer": <your reply, or null>}.'
                    ."\n\nFAQ entries:\n".json_encode($faqs, JSON_UNESCAPED_UNICODE)],
                ['role' => 'user', 'content' => Str::limit($question, self::MAX_QUESTION_CHARS, '')],
            ]);
        } catch (Throwable $e) {
            Log::warning('[SupportAutoReply] AI call failed: '.$e->getMessage());

            return null;
        }

        if (! ($result['success'] ?? false) || trim((string) ($result['content'] ?? '')) === '') {
            // The provider's own words (keys, quotas…) are for the logs, not for the customer.
            Log::warning('[SupportAutoReply] '.$provider->key.': '.($result['message'] ?? 'empty answer'));

            return null;
        }

        return $this->parse((string) $result['content'], array_column($faqs, 'id'));
    }

    /**
     * The published general FAQs in the customer's language: id, question, answer (trimmed).
     *
     * @return list<array{id: int, question: string, answer: string}>
     */
    private function catalog(string $locale): array
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $this->faqs->generalActive()
                ->take(self::MAX_FAQS)
                ->map(fn (Faq $faq) => [
                    'id' => (int) $faq->id,
                    'question' => trim((string) $faq->translated('question')),
                    'answer' => Str::limit(trim(strip_tags((string) $faq->translated('answer'))), self::MAX_ANSWER_CHARS),
                ])
                ->filter(fn (array $faq) => $faq['question'] !== '' && $faq['answer'] !== '')
                ->values()
                ->all();
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * @param  list<int>  $ids  the FAQ ids the model was shown — an answer must name one of them
     * @return array{answer: string, faq_id: int}|null
     */
    private function parse(string $raw, array $ids): ?array
    {
        $json = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', trim($raw))), true);

        if (! is_array($json)) {
            return null;
        }

        $faqId = is_numeric($json['faq_id'] ?? null) ? (int) $json['faq_id'] : null;
        $answer = is_string($json['answer'] ?? null) ? trim($json['answer']) : '';

        if ($faqId === null || ! in_array($faqId, $ids, true) || $answer === '') {
            return null;
        }

        return ['answer' => Str::limit($answer, 1200), 'faq_id' => $faqId];
    }
}
