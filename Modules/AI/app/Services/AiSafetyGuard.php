<?php

namespace Modules\AI\Services;

use Illuminate\Support\Str;
use Modules\AI\Models\AiSafetyRule;

/**
 * Evaluates an outgoing chat message against the admin-configured safety
 * policies/rules (Phase 5) before it is ever sent to a real AI provider.
 *
 * Two condition "type"s are supported (matching what the seeder and the
 * admin screens already produce for ai_safety_rules.condition):
 * - keyword_match: triggers when the message contains any of "keywords"
 *   (case-insensitive, works for Arabic and Latin text alike).
 * - risk_score: a lightweight heuristic (no external moderation model is
 *   wired up yet) that scores the message against a short list of
 *   high-risk term categories and triggers when the score clears
 *   "min_score".
 */
class AiSafetyGuard
{
    /**
     * Heuristic-only categories used by the "risk_score" condition type
     * until a real moderation provider is connected. Kept intentionally
     * small and pattern-level (never a literal how-to phrase) so this
     * class stays safe to read/extend on its own.
     *
     * @var array<string, list<string>>
     */
    protected array $riskCategories = [
        'violence' => ['اقتل', 'kill', 'slaughter', 'اذبح'],
        'weapons' => ['قنبلة', 'bomb', 'تفجير', 'explosive'],
        'self_harm' => ['انتحار', 'suicide', 'self harm', 'اذي نفسي'],
        'hacking' => ['اخترق', 'hack into', 'malware', 'فيروس اختراق'],
    ];

    /**
     * @return array{action: string, rule: ?AiSafetyRule, sanitized: ?string}
     */
    public function evaluate(string $content): array
    {
        $rules = AiSafetyRule::query()
            ->where('is_active', true)
            ->whereHas('policy', fn ($query) => $query->where('is_active', true))
            ->with('policy')
            ->orderByDesc('priority')
            ->get();

        foreach ($rules as $rule) {
            $condition = $rule->condition ?? [];
            $type = $condition['type'] ?? null;

            if ($type === 'keyword_match' && $this->matchesKeywords($content, $condition['keywords'] ?? [])) {
                return [
                    'action' => $rule->action,
                    'rule' => $rule,
                    'sanitized' => $rule->action === AiSafetyRule::ACTION_SANITIZE
                        ? $this->sanitize($content, $condition['keywords'] ?? [])
                        : null,
                ];
            }

            if ($type === 'risk_score' && $this->riskScore($content) >= (float) ($condition['min_score'] ?? 1)) {
                return ['action' => $rule->action, 'rule' => $rule, 'sanitized' => null];
            }
        }

        return ['action' => AiSafetyRule::ACTION_ALLOW, 'rule' => null, 'sanitized' => null];
    }

    /**
     * @param  list<string>  $keywords
     */
    protected function matchesKeywords(string $content, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && Str::contains(mb_strtolower($content), mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $keywords
     */
    protected function sanitize(string $content, array $keywords): string
    {
        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }

            $content = preg_replace('/'.preg_quote($keyword, '/').'/ui', str_repeat('*', mb_strlen($keyword)), $content) ?? $content;
        }

        return $content;
    }

    protected function riskScore(string $content): float
    {
        $lower = mb_strtolower($content);
        $matched = 0;

        foreach ($this->riskCategories as $terms) {
            foreach ($terms as $term) {
                if (Str::contains($lower, mb_strtolower($term))) {
                    $matched++;
                    break;
                }
            }
        }

        return count($this->riskCategories) > 0 ? $matched / count($this->riskCategories) : 0.0;
    }
}
