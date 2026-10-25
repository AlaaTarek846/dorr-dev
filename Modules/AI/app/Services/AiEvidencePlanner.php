<?php

namespace Modules\AI\Services;

use Modules\AI\Safety\RiskAssessment;
use Modules\AI\Safety\RiskClassifier;

/**
 * EvidencePlanner (spec 324): before an answer, what evidence it must rest on and how it must speak
 * about it — per topic, as one instruction for the model. The rules the system can check itself
 * afterwards are checked again by AiCitationValidator.
 *
 * - Always (spec 5, 9, 275–276, 280–283): never claim 100% accuracy; show a disagreement between
 *   reliable sources instead of picking one; primary/official sources first, a high-quality
 *   secondary one only when needed; check dates and jurisdiction, units/currency/math, names and
 *   identifiers, and quotes/attributions before answering.
 * - Law (16, 79–81), religion (18, 86–93), dream interpretation (19, 95–98), finance and business
 *   (17, 109), data analysis (112), media and content (105).
 *
 * Topics come from the safety layer's own keyword rules (RiskClassifier::byRules — no extra model
 * call) plus the keywords below.
 */
class AiEvidencePlanner
{
    public const TOPIC_DREAMS = 'dreams';

    public const TOPIC_FINANCE = 'finance';

    public const TOPIC_DATA = 'data';

    public const TOPIC_MEDIA = 'media';

    private const KEYWORDS = [
        self::TOPIC_DREAMS => ['حلم', 'حلمت', 'احلام', 'أحلام', 'رؤيا', 'رؤية في المنام', 'المنام', 'منام', 'شفت في المنام', 'تفسير الحلم', 'dream', 'dreamt', 'dreamed', 'nightmare'],
        self::TOPIC_FINANCE => ['استثمار', 'استثمر', 'أستثمر', 'اسهم', 'أسهم', 'سهم', 'بورصة', 'تداول', 'عملة رقمية', 'عملات رقمية', 'بيتكوين', 'كريبتو', 'فوركس', 'صندوق استثمار', 'عائد', 'أرباح', 'ارباح', 'قرض', 'فايدة', 'فائدة', 'دراسة جدوى', 'invest', 'investment', 'stock', 'shares', 'crypto', 'bitcoin', 'forex', 'trading', 'portfolio', 'roi', 'loan', 'interest rate', 'feasibility'],
        self::TOPIC_DATA => ['ارتباط', 'علاقة بين', 'إحصائ', 'احصائ', 'تحليل البيانات', 'تحليل بيانات', 'بيانات', 'نسبة مئوية', 'متوسط', 'correlation', 'regression', 'statistic', 'dataset', 'data analysis', 'causation'],
        self::TOPIC_MEDIA => ['اكتب مقال', 'مقال', 'منشور', 'بوست', 'كابشن', 'إعلان', 'اعلان', 'محتوى', 'سكريبت', 'تغريدة', 'article', 'blog post', 'caption', 'ad copy', 'script', 'tweet', 'content'],
    ];

    public function __construct(protected RiskClassifier $risk) {}

    /**
     * The topics a request touches: the safety layer's domain (religion, law, medicine,
     * engineering, code) plus dreams, finance, data and media.
     *
     * @return list<string>
     */
    public function topics(string $content): array
    {
        $topics = [];
        $domain = $this->risk->byRules($content)->domain;
        if ($domain !== null) {
            $topics[] = $domain;
        }

        $haystack = ' '.mb_strtolower($content).' ';
        foreach (self::KEYWORDS as $topic => $words) {
            foreach ($words as $word) {
                if (str_contains($haystack, mb_strtolower($word))) {
                    $topics[] = $topic;
                    break;
                }
            }
        }

        return array_values(array_unique($topics));
    }

    /**
     * The evidence rules for this request, as one instruction.
     */
    public function guidance(string $content): string
    {
        $lines = [
            'Evidence and accuracy rules: never claim to be 100% accurate. Use primary or official sources first; use a high-quality secondary source only when no primary one is available or enough, and say which it is. '
            .'If reliable sources disagree, present the disagreement and its main positions instead of choosing one as certain. '
            .'Before answering, check: dates and the jurisdiction they apply to; units, currencies and every calculation (show the arithmetic when numbers matter); names and identifiers; and that every quote is exact and attributed to who really said it. Never cite a source you did not use.',
        ];

        foreach ($this->topics($content) as $topic) {
            $lines[] = match ($topic) {
                RiskAssessment::LAW => 'Law: name the country, jurisdiction and the date the rule applies from before stating it; rely on official legal texts and authorities and the most recent version you know of (say when it may have changed); keep apart what the legal text says, how it is interpreted, and opinion.',
                RiskAssessment::RELIGION => 'Religion: quote the Qur\'an only with its exact text, surah name and verse number — and only when you are sure of all three; never give a personal interpretation (tafsir) of a verse, and attribute a meaning only to a named, known exegete. '
                    .'For a hadith, give its source collection and its grading when that matters to the question; never attribute anything to a scholar you cannot name with confidence. Where there is a recognised difference of juristic opinion, state it.',
                self::TOPIC_DREAMS => 'Dream interpretation: it is speculative opinion, never certain knowledge or a prediction of the future. Attribute an interpretation to a known interpreter only when you are sure it is theirs. '
                    .'Never base a financial, medical or life decision on a dream, and say so if the person seems to. In Arabic end with «والله أعلم».',
                self::TOPIC_FINANCE => 'Finance and business: separate the person\'s own figures from outside data, cite official or reliable recent sources for rates and prices, and show calculations so they can be re-checked. '
                    .'No personal high-risk investment recommendation without stating the risks and that recent sources are needed; never promise returns.',
                self::TOPIC_DATA => 'Data analysis: never present a correlation as causation without evidence; state sample size and limits when they matter.',
                self::TOPIC_MEDIA => 'Media and content: clearly separate creative suggestions from facts that need checking; never invent statistics, quotes or results.',
                default => null,
            };
        }

        return implode("\n", array_filter($lines));
    }
}
