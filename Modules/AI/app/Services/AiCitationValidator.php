<?php

namespace Modules\AI\Services;

/**
 * CitationValidator (spec 332, 280–283, 88, 97): what the system can check in an answer by itself,
 * without trusting the model — after it is written, before the person sees it.
 *
 * - Qur'an references: the surah exists and the verse number is within it (spec 88). Checked from the
 *   fixed table below (114 surahs, 6236 verses), never from the model.
 * - Source markers [n] that point to no source the answer was given are removed (spec 278, 332).
 * - Arithmetic written as "a op b = c" and "x% of y = z" is recomputed (spec 281).
 * - Impossible calendar dates (31/02/2026) are flagged (spec 280).
 * - A legal answer with no country or jurisdiction anywhere is flagged (spec 79, 280).
 * - A dream interpretation in Arabic closes with «والله أعلم» (spec 97).
 *
 * Problems come back as warnings in the reply's language (the message's verification_warnings).
 */
class AiCitationValidator
{
    /** Surah name (Arabic, normalized) → [number, verse count]. Aliases included. */
    private const SURAHS = [
        'الفاتحه' => [1, 7], 'البقره' => [2, 286], 'ال عمران' => [3, 200], 'النساء' => [4, 176], 'المايده' => [5, 120],
        'الانعام' => [6, 165], 'الاعراف' => [7, 206], 'الانفال' => [8, 75], 'التوبه' => [9, 129], 'براءه' => [9, 129],
        'يونس' => [10, 109], 'هود' => [11, 123], 'يوسف' => [12, 111], 'الرعد' => [13, 43], 'ابراهيم' => [14, 52],
        'الحجر' => [15, 99], 'النحل' => [16, 128], 'الاسراء' => [17, 111], 'بني اسرائيل' => [17, 111], 'الكهف' => [18, 110],
        'مريم' => [19, 98], 'طه' => [20, 135], 'الانبياء' => [21, 112], 'الحج' => [22, 78], 'المؤمنون' => [23, 118],
        'النور' => [24, 64], 'الفرقان' => [25, 77], 'الشعراء' => [26, 227], 'النمل' => [27, 93], 'القصص' => [28, 88],
        'العنكبوت' => [29, 69], 'الروم' => [30, 60], 'لقمان' => [31, 34], 'السجده' => [32, 30], 'الاحزاب' => [33, 73],
        'سبا' => [34, 54], 'فاطر' => [35, 45], 'يس' => [36, 83], 'الصافات' => [37, 182], 'ص' => [38, 88],
        'الزمر' => [39, 75], 'غافر' => [40, 85], 'المؤمن' => [40, 85], 'فصلت' => [41, 54], 'الشوري' => [42, 53],
        'الزخرف' => [43, 89], 'الدخان' => [44, 59], 'الجاثيه' => [45, 37], 'الاحقاف' => [46, 35], 'محمد' => [47, 38],
        'الفتح' => [48, 29], 'الحجرات' => [49, 18], 'ق' => [50, 45], 'الذاريات' => [51, 60], 'الطور' => [52, 49],
        'النجم' => [53, 62], 'القمر' => [54, 55], 'الرحمن' => [55, 78], 'الواقعه' => [56, 96], 'الحديد' => [57, 29],
        'المجادله' => [58, 22], 'الحشر' => [59, 24], 'الممتحنه' => [60, 13], 'الصف' => [61, 14], 'الجمعه' => [62, 11],
        'المنافقون' => [63, 11], 'التغابن' => [64, 18], 'الطلاق' => [65, 12], 'التحريم' => [66, 12], 'الملك' => [67, 30],
        'القلم' => [68, 52], 'الحاقه' => [69, 52], 'المعارج' => [70, 44], 'نوح' => [71, 28], 'الجن' => [72, 28],
        'المزمل' => [73, 20], 'المدثر' => [74, 56], 'القيامه' => [75, 40], 'الانسان' => [76, 31], 'الدهر' => [76, 31],
        'المرسلات' => [77, 50], 'النبا' => [78, 40], 'النازعات' => [79, 46], 'عبس' => [80, 42], 'التكوير' => [81, 29],
        'الانفطار' => [82, 19], 'المطففين' => [83, 36], 'الانشقاق' => [84, 25], 'البروج' => [85, 22], 'الطارق' => [86, 17],
        'الاعلي' => [87, 19], 'الغاشيه' => [88, 26], 'الفجر' => [89, 30], 'البلد' => [90, 20], 'الشمس' => [91, 15],
        'الليل' => [92, 21], 'الضحي' => [93, 11], 'الشرح' => [94, 8], 'الانشراح' => [94, 8], 'التين' => [95, 8],
        'العلق' => [96, 19], 'القدر' => [97, 5], 'البينه' => [98, 8], 'الزلزله' => [99, 8], 'العاديات' => [100, 11],
        'القارعه' => [101, 11], 'التكاثر' => [102, 8], 'العصر' => [103, 3], 'الهمزه' => [104, 9], 'الفيل' => [105, 5],
        'قريش' => [106, 4], 'الماعون' => [107, 7], 'الكوثر' => [108, 3], 'الكافرون' => [109, 6], 'النصر' => [110, 3],
        'المسد' => [111, 5], 'اللهب' => [111, 5], 'الاخلاص' => [112, 4], 'الفلق' => [113, 5], 'الناس' => [114, 6],
    ];

    public function __construct(
        protected AiEvidencePlanner $planner,
        protected AiDomainPipelineService $domains,
    ) {}

    /**
     * @return array{text: string, warnings: list<string>}
     */
    public function validate(string $answer, string $question, int $sourceCount = 0): array
    {
        $warnings = [];
        $arabic = preg_match('/\p{Arabic}/u', $answer) === 1;

        foreach ($this->badQuranReferences($answer) as $ref) {
            $warnings[] = __('ai.citation_quran_reference', ['ref' => $ref]);
        }

        [$answer, $dropped] = $this->dropUnknownMarkers($answer, $sourceCount);
        if ($dropped !== []) {
            $warnings[] = __('ai.citation_unknown_source', ['refs' => implode(', ', $dropped)]);
        }

        foreach ($this->wrongArithmetic($answer) as [$written, $correct]) {
            $warnings[] = __('ai.citation_math', ['written' => $written, 'correct' => $correct]);
        }

        foreach ($this->impossibleDates($answer) as $date) {
            $warnings[] = __('ai.citation_date', ['date' => $date]);
        }

        $topics = $this->planner->topics($question);
        if (in_array('law', $topics, true) && ! $this->domains->mentionsJurisdiction($question.' '.$answer)) {
            $warnings[] = __('ai.citation_jurisdiction');
        }

        if (in_array(AiEvidencePlanner::TOPIC_DREAMS, $topics, true) && $arabic && ! str_contains($this->normalize($answer), 'والله اعلم')) {
            $answer = rtrim($answer)."\n\nوالله أعلم.";
        }

        return ['text' => $answer, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * References to a surah that doesn't exist or a verse beyond its last one.
     *
     * @return list<string>
     */
    public function badQuranReferences(string $text): array
    {
        $text = $this->westernDigits($text);
        $bad = [];

        // "سورة البقرة آية 300", "سورة آل عمران، الآية رقم 5".
        preg_match_all('/سور[ةه]\s+([\p{Arabic}]+(?:\s+[\p{Arabic}]+)?)[^\d\n]{0,25}?(?:الآيات|الايات|آيات|ايات|الآية|الاية|آية|اية|آيه)\s*(?:رقم\s*)?(\d{1,3})/u', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as [$whole, $name, $verse]) {
            $surah = $this->surah($name);
            if ($surah === null || (int) $verse < 1 || (int) $verse > $surah[1]) {
                $bad[] = trim($whole);
            }
        }

        // "(2:255)", "Qur'an 2:300", "سورة 115:3" — only next to a Qur'an word, never a clock time.
        preg_match_all('/(?:qur.?an|surah?|sura|القرآن|القران|سورة|سوره)[^\d\n]{0,15}?(\d{1,3})\s*:\s*(\d{1,3})/iu', $text, $numeric, PREG_SET_ORDER);
        foreach ($numeric as [$whole, $number, $verse]) {
            $counts = array_column(array_values(self::SURAHS), 1, 0);
            if (! isset($counts[(int) $number]) || (int) $verse < 1 || (int) $verse > $counts[(int) $number]) {
                $bad[] = trim($whole);
            }
        }

        return array_values(array_unique($bad));
    }

    /**
     * Every "a op b = c" and "x% of y = z" whose result is wrong, with the right one.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function wrongArithmetic(string $text): array
    {
        $text = $this->westernDigits($text);
        $number = '(\d[\d,]*(?:\.\d+)?)';
        $wrong = [];

        preg_match_all('/(?<![\d.,])'.$number.'\s*([+\-−×x*÷\/])\s*'.$number.'\s*=\s*(-?\d[\d,]*(?:\.\d+)?)/u', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as [$whole, $a, $op, $b, $c]) {
            [$a, $b, $c] = [$this->number($a), $this->number($b), $this->number($c)];
            $expected = match ($op) {
                '+' => $a + $b,
                '-', '−' => $a - $b,
                '×', 'x', '*' => $a * $b,
                default => $b == 0.0 ? null : $a / $b,
            };
            if ($expected !== null && ! $this->close($expected, $c)) {
                $wrong[] = [trim($whole), $this->format($expected)];
            }
        }

        preg_match_all('/'.$number.'\s*%\s*(?:من|of)\s*'.$number.'\s*(?:=|يساوي|يساوى|تساوي|هو|هي|is|equals)\s*'.$number.'/iu', $text, $percents, PREG_SET_ORDER);
        foreach ($percents as [$whole, $p, $of, $c]) {
            $expected = $this->number($p) * $this->number($of) / 100;
            if (! $this->close($expected, $this->number($c))) {
                $wrong[] = [trim($whole), $this->format($expected)];
            }
        }

        return $wrong;
    }

    /** @return list<string> */
    public function impossibleDates(string $text): array
    {
        $text = $this->westernDigits($text);
        $bad = [];

        preg_match_all('/(?<!\d)(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/', $text, $iso, PREG_SET_ORDER);
        foreach ($iso as [$whole, $y, $m, $d]) {
            if (! checkdate((int) $m, (int) $d, (int) $y)) {
                $bad[] = $whole;
            }
        }

        // 31/02/2026 — wrong whichever way round (day/month or month/day).
        preg_match_all('/(?<![\d\/])(\d{1,2})\/(\d{1,2})\/(\d{4})(?![\d\/])/', $text, $slashed, PREG_SET_ORDER);
        foreach ($slashed as [$whole, $first, $second, $y]) {
            if (! checkdate((int) $second, (int) $first, (int) $y) && ! checkdate((int) $first, (int) $second, (int) $y)) {
                $bad[] = $whole;
            }
        }

        return array_values(array_unique($bad));
    }

    /**
     * Removes [n] markers past the last source the answer was given (none checked without sources).
     *
     * @return array{0: string, 1: list<string>}
     */
    protected function dropUnknownMarkers(string $answer, int $sourceCount): array
    {
        if ($sourceCount < 1) {
            return [$answer, []];
        }

        $dropped = [];
        $answer = preg_replace_callback('/\s?\[(\d{1,3})\]/', function (array $m) use ($sourceCount, &$dropped) {
            if ((int) $m[1] >= 1 && (int) $m[1] <= $sourceCount) {
                return $m[0];
            }
            $dropped[] = '['.$m[1].']';

            return '';
        }, $answer) ?? $answer;

        return [$answer, array_values(array_unique($dropped))];
    }

    /** @return array{0: int, 1: int}|null */
    protected function surah(string $name): ?array
    {
        $name = $this->normalize(trim($name));
        $words = preg_split('/\s+/u', $name) ?: [];

        // Two words first ("آل عمران"), then the first one alone ("البقرة آية…").
        foreach ([$name, $words[0] ?? ''] as $candidate) {
            foreach ([$candidate, 'ال'.$candidate, preg_replace('/^ال/u', '', $candidate)] as $form) {
                if (isset(self::SURAHS[$form])) {
                    return self::SURAHS[$form];
                }
            }
        }

        return null;
    }

    protected function normalize(string $text): string
    {
        return strtr(mb_strtolower($text), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ئ' => 'ي', "\u{064E}" => '', "\u{064F}" => '', "\u{0650}" => '', "\u{0651}" => '', "\u{0652}" => '']);
    }

    protected function westernDigits(string $text): string
    {
        return strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => ',']);
    }

    /** "1,500" is fifteen hundred; "1,5" is one and a half. */
    protected function number(string $raw): float
    {
        $raw = preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $raw) === 1 ? str_replace(',', '', $raw) : str_replace(',', '.', $raw);

        return (float) $raw;
    }

    protected function close(float $expected, float $written): bool
    {
        return abs($expected - $written) <= max(0.01, abs($expected) * 0.005);
    }

    protected function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }
}
