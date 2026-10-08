<?php

namespace Modules\AI\Services;

use Illuminate\Support\Str;
use Modules\AI\Models\AiDomainPolicy;

/**
 * v2.0 requirements doc §7-14: classifies an incoming message into one
 * of the admin-managed ai_domain_policies rows, independently of
 * AiIntentClassifier (which only decides provider/model routing).
 * A domain policy governs *safety and quality* behavior - disclaimers,
 * jurisdiction/triage gating, sandboxed code execution - not which
 * provider answers.
 *
 * Same transparent keyword-map trade-off already used by
 * AiIntentClassifier and AiSafetyGuard: no ML classifier is wired up,
 * so this stays simple, auditable and easy for an admin to extend
 * later without redeploying.
 */
class AiDomainClassifier
{
    /**
     * @var array<string, list<string>>
     */
    protected array $keywordsByDomainKey = [
        AiDomainPolicy::DOMAIN_HEALTH => [
            'وجع', 'ألم', 'أعراض', 'مريض', 'دوا', 'دواء', 'علاج', 'تشخيص', 'حمى', 'سخونية',
            'symptom', 'diagnosis', 'medication', 'treatment', 'pain', 'fever', 'doctor',
        ],
        AiDomainPolicy::DOMAIN_LEGAL => [
            'قانون', 'محامي', 'محكمة', 'عقد', 'دعوى', 'قضية', 'لائحة', 'تشريع', 'حق قانوني',
            'law', 'legal', 'lawyer', 'attorney', 'court', 'contract', 'lawsuit', 'regulation',
        ],
        AiDomainPolicy::DOMAIN_EDUCATION => [
            'منهج', 'مدرسة', 'امتحان', 'واجب', 'شرح لي', 'ازاي افهم', 'مادة دراسية', 'صف',
            'curriculum', 'homework', 'exam', 'study', 'explain this lesson', 'grade level',
        ],
        AiDomainPolicy::DOMAIN_CODE => [
            'كود', 'اكتب لي فانكشن', 'اعمل لي كلاس', 'debug', 'اصلح الكود',
            'function', 'class ', 'code', 'script', 'bug', 'compile', 'exception', 'stack trace',
            '```',
        ],
        AiDomainPolicy::DOMAIN_MARKETING => [
            'حملة تسويقية', 'اعلان', 'محتوى تسويقي', 'سلوجان', 'وصف منتج',
            'marketing campaign', 'ad copy', 'slogan', 'product description', 'social media post',
        ],
    ];

    public function classify(string $content): ?AiDomainPolicy
    {
        $lower = mb_strtolower($content);

        foreach ($this->keywordsByDomainKey as $domainKey => $keywords) {
            foreach ($keywords as $keyword) {
                if (Str::contains($lower, mb_strtolower($keyword))) {
                    $policy = AiDomainPolicy::query()
                        ->where('domain_key', $domainKey)
                        ->where('is_active', true)
                        ->first();

                    if ($policy) {
                        return $policy;
                    }
                }
            }
        }

        // No domain keyword matched at all - falls back to general_info
        // (§14) if configured, since even "normal" questions can carry a
        // time-sensitive fact vs. stable-knowledge distinction.
        return AiDomainPolicy::query()
            ->where('domain_key', AiDomainPolicy::DOMAIN_GENERAL_INFO)
            ->where('is_active', true)
            ->first();
    }
}
