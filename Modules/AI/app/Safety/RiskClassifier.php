<?php

namespace Modules\AI\Safety;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\AiGateway;
use Throwable;

/**
 * RiskClassifier (spec 351, 353, 355, 357, 359, 360): classifies a request **before** any answer
 * is generated — it is a step of the system, never something the answering model is trusted to
 * remember.
 *
 * Two layers: a short, separate classification call (JSON only), and fixed keyword rules in
 * Arabic and English. The rules decide alone when the model can't answer, and they can only make
 * the result stricter: a personal/specific signal from either one counts.
 */
class RiskClassifier
{
    /** Domain keywords (lower-case, matched as words or word starts). */
    private const KEYWORDS = [
        RiskAssessment::RELIGION => [
            'حلال', 'حرام', 'فتوى', 'فتوي', 'الشرع', 'شرعا', 'شرعي', 'مكروه', 'يجوز', 'جائز', 'الصلاة', 'صلاة', 'الصيام', 'صيام', 'الزكاة', 'زكاة',
            'الحج', 'العمرة', 'الطلاق', 'طلاق', 'الربا', 'ربا', 'الميراث', 'ميراث', 'نذر', 'كفارة', 'وضوء', 'الوضوء', 'العدة', 'آية', 'الإسلام', 'الدين', 'رمضان',
            'halal', 'haram', 'fatwa', 'sharia', 'islamic ruling', 'permissible', 'zakat', 'prayer', 'fasting', 'religious',
        ],
        RiskAssessment::LAW => [
            'قانون', 'قانوني', 'قانونا', 'محامي', 'محامى', 'محكمة', 'قضية', 'دعوى', 'العقد', 'عقد عمل', 'عقد إيجار', 'نزاع', 'غرامة', 'مخالفة', 'جريمة', 'شكوى رسمية', 'كفالة',
            'إقامة', 'الإقامة', 'تأشيرة', 'فصل تعسفي', 'تعويض', 'نيابة', 'حقوقي', 'نظام العمل', 'إيجار', 'الإيجار', 'المؤجر', 'ورث',
            'law', 'legal', 'lawyer', 'court', 'lawsuit', 'sue', 'contract', 'dispute', 'visa', 'residency', 'tenant', 'landlord', 'liability', 'custody',
        ],
        RiskAssessment::MEDICINE => [
            'طبيب', 'دكتور', 'مرض', 'المرض', 'أعراض', 'اعراض', 'علاج', 'دواء', 'ادوية', 'أدوية', 'جرعة', 'تشخيص', 'ألم ', 'وجع', 'صداع', 'السكر', 'سكري',
            'ضغط الدم', 'أنا حامل', 'انا حامل', 'حامل في الشهر', 'حامل بالشهر', 'نزيف', 'التهاب', 'حساسية', 'سرطان', 'اكتئاب', 'قلق نفسي', 'مضاد حيوي', 'تحاليل', 'تحليل دم', 'عملية جراحية', 'رجيم',
            'doctor', 'disease', 'symptom', 'symptoms', 'treatment', 'medicine', 'medication', 'dose', 'dosage', 'diagnosis', 'pain', 'fever', 'pregnant',
            'bleeding', 'infection', 'allergy', 'cancer', 'depression', 'antibiotic', 'diabetes', 'blood pressure', 'surgery',
        ],
        RiskAssessment::ENGINEERING => [
            'جدار حامل', 'حائط حامل', 'عمود', 'أعمدة', 'سقف', 'خرسانة', 'تسليح', 'أساسات', 'إنشائي', 'انشائي', 'تمديدات', 'كهرباء', 'كهربائي', 'سباكة',
            'مواسير', 'تكييف مركزي', 'هدم', 'دور إضافي', 'ملحق', 'حمل المبنى',
            'load-bearing', 'load bearing', 'beam', 'column', 'structural', 'foundation', 'rebar', 'concrete', 'wiring', 'electrical', 'plumbing', 'demolish',
        ],
        RiskAssessment::CODE => [
            'كود', 'برمجة', 'سكريبت', 'دالة', 'خوارزمية', 'قاعدة بيانات', 'api',
            'code', 'function', 'script', 'sql', 'query', 'php', 'python', 'javascript', 'kotlin', 'java', 'laravel', 'react', 'algorithm', 'bug', 'deploy',
        ],
    ];

    /** Signs it's about the person's own case (the "(b)" side). */
    private const SPECIFIC = [
        RiskAssessment::RELIGION => ['أنا ', 'انا ', 'عندي', 'زوجي', 'زوجتي', 'حالتي', 'أبي', 'امي', 'أمي', 'ابني', 'بنتي', 'هل يجوز لي', 'my ', ' i ', "i'm", 'my husband', 'my wife'],
        RiskAssessment::LAW => ['عقدي', 'قضيتي', 'كفيلي', 'مديري', 'شركتي', 'صاحب العمل', 'المؤجر', 'رفعت', 'أرفع', 'ارفع', 'اتفصلت', 'فصلوني', 'عندي', 'my ', ' i ', "i'm", 'sued me', 'my employer', 'my landlord'],
        RiskAssessment::MEDICINE => ['عندي', 'أعاني', 'اعاني', 'بحس', 'أحس', 'احس', 'ابني', 'بنتي', 'أمي', 'امي', 'أبوي', 'زوجي', 'زوجتي', 'آخد', 'اخد', 'أخذ', 'my ', ' i ', "i'm", 'i have', 'i feel', 'my son', 'my daughter'],
        RiskAssessment::ENGINEERING => ['جدار حامل', 'حائط حامل', 'عمود', 'أعمدة', 'سقف', 'خرسانة', 'تسليح', 'أساسات', 'تمديدات', 'كهرباء', 'سباكة', 'مواسير', 'هدم', 'دور إضافي', 'load-bearing', 'load bearing', 'beam', 'column', 'structural', 'foundation', 'wiring', 'plumbing', 'demolish'],
        RiskAssessment::CODE => ['production', 'prod', 'live', 'payment', 'payments', 'wallet', 'bank', 'password', 'auth', 'security', 'users', 'customer', 'إنتاج', 'انتاج', 'دفع', 'محفظة', 'بنك', 'كلمة المرور', 'كلمة السر', 'أمان', 'امان', 'عملاء', 'مستخدمين'],
    ];

    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * @param  AiProvider|null  $provider  for the classification call; null = rules only
     */
    public function classify(string $request, ?AiProvider $provider = null): RiskAssessment
    {
        $request = Str::limit(trim($request), 2000, '');
        $rules = $this->byRules($request);
        if ($request === '' || $provider === null) {
            return $rules;
        }

        $model = $this->byModel($request, $provider);
        if ($model === null) {
            return $rules;
        }

        // The model knows the domain better than keywords; a specific signal from either counts.
        $domain = $model->domain;
        $specific = $model->specific || ($domain !== null && $domain === $rules->domain && $rules->specific);

        return new RiskAssessment($domain, $specific, 'model');
    }

    public function byRules(string $request): RiskAssessment
    {
        $text = ' '.mb_strtolower($request).' ';
        $scores = [];
        foreach (self::KEYWORDS as $domain => $words) {
            // A phrase weighs more than a single word ("جدار حامل" beats a lone "حامل").
            $scores[$domain] = collect($words)->filter(fn ($w) => $this->has($text, $w))->sum(fn ($w) => count(preg_split('/\s+/u', trim($w))));
        }
        arsort($scores);
        $domain = array_key_first($scores);
        if ($domain === null || $scores[$domain] === 0) {
            return RiskAssessment::none();
        }

        $specific = collect(self::SPECIFIC[$domain])->contains(fn ($w) => $this->has($text, $w));

        return new RiskAssessment($domain, $specific);
    }

    private function has(string $text, string $word): bool
    {
        $word = mb_strtolower($word);
        if (preg_match('/^[\x20-\x7E]+$/', $word) && $word === trim($word)) {
            // Latin words: whole words only ("pain", not "painting").
            return (bool) preg_match('/(?<![a-z0-9])'.preg_quote($word, '/').'(?![a-z0-9])/u', $text);
        }

        // Arabic (which takes prefixes: ال، و، ب، ف…) and phrases with their spaces: as written.
        return str_contains($text, $word);
    }

    private function byModel(string $request, AiProvider $provider): ?RiskAssessment
    {
        try {
            $result = $this->gateway->chat($provider, [
                ['role' => 'system', 'content' => 'You classify a request before it is answered. Reply with JSON only: {"domain": "religion" | "law" | "medicine" | "engineering" | "code" | "none", "specific": true | false}. '
                    .'religion = religious rulings, worship, halal/haram, fatwa. law = laws, contracts, disputes, rights, courts, fines, visas/residency. '
                    .'medicine = health, symptoms, illness, medicines, doses, treatment, pregnancy, mental health. '
                    .'engineering = building, interior or architectural design touching structure, load-bearing elements, electrical wiring or plumbing. '
                    .'code = writing, fixing or reviewing software. none = anything else. '
                    .'specific = true when it is about the person\'s own case (their contract, dispute, symptoms, treatment, own religious situation); '
                    .'for engineering when it touches a structural, electrical or plumbing element; for code when it will run in production or handle money, security or real user data.'],
                ['role' => 'user', 'content' => $request],
            ]);
            if (! $result['success']) {
                return null;
            }

            $raw = trim((string) preg_replace('/^```(?:json)?|```$/m', '', (string) $result['content']));
            $json = json_decode(substr($raw, (int) strcspn($raw, '{')), true);
            if (! is_array($json) || ! array_key_exists('domain', $json)) {
                return null;
            }
            $domain = in_array($json['domain'], RiskAssessment::DOMAINS, true) ? $json['domain'] : null;

            return new RiskAssessment($domain, $domain !== null && filter_var($json['specific'] ?? false, FILTER_VALIDATE_BOOLEAN), 'model');
        } catch (Throwable $e) {
            Log::warning('[RiskClassifier] '.$e->getMessage());

            return null;
        }
    }
}
