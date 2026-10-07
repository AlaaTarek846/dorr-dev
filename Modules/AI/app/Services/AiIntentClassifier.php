<?php

namespace Modules\AI\Services;

use Illuminate\Support\Str;
use Modules\AI\Models\AiIntent;

/**
 * Classifies an incoming chat message into one of the admin-managed
 * ai_intents rows (Phase 4), so AiRoutingEngine can look up which
 * provider/model should actually handle it via ai_routing_rules.
 *
 * No ML classifier is wired up yet, so this is a transparent, extensible
 * keyword map keyed by the intent's own "key" column - the same
 * trade-off already used for AiSafetyGuard's "risk_score" heuristic.
 * Admins can rename/describe intents freely from the admin screen; only
 * the "key" needs to stay one of the ones mapped below (or be added here)
 * for automatic classification to pick it up.
 *
 * @see AiRoutingEngine
 */
class AiIntentClassifier
{
    /**
     * @var array<string, list<string>>
     */
    protected array $keywordsByIntentKey = [
        'support' => [
            'مشكلة', 'مش شغال', 'خطأ', 'مساعدة', 'اشتكي', 'شكوى', 'ازاي استخدم', 'ازاي احجز',
            'problem', 'issue', 'error', 'help me', 'not working', 'how do i', 'support',
        ],
        'content_generation' => [
            'اكتب', 'اعمل وصف', 'اعملي', 'صياغة', 'مقال', 'اعلان', 'محتوى تسويقي', 'وصف منتج',
            'write', 'draft', 'generate a description', 'marketing copy', 'compose', 'blog post',
        ],
        'recommendation' => [
            'رشحلي', 'اقترح', 'انصحني', 'افضل', 'مين احسن', 'وريني خيارات',
            'recommend', 'suggest', 'which is best', 'what should i choose',
        ],
    ];

    public function classify(string $content): ?AiIntent
    {
        $lower = mb_strtolower($content);

        foreach ($this->keywordsByIntentKey as $intentKey => $keywords) {
            foreach ($keywords as $keyword) {
                if (Str::contains($lower, mb_strtolower($keyword))) {
                    $intent = AiIntent::query()->where('key', $intentKey)->where('is_active', true)->first();

                    if ($intent) {
                        return $intent;
                    }
                }
            }
        }

        // Falls back to the generic "chat" intent (seeded by default) so
        // routing rules can still target "general conversation" explicitly
        // when nothing more specific matched.
        return AiIntent::query()->where('key', 'chat')->where('is_active', true)->first();
    }
}
