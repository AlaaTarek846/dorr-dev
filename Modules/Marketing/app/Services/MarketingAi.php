<?php

namespace Modules\Marketing\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\AI\Services\AiTaskRunner;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\Budgets\BudgetService;

/**
 * The marketing director's AI, through the one AI system (AiTaskRunner: safety, routing, fallback, logs). The
 * marketing package pays for these calls, so no DORR AI plan is asked for (`free`). Answers are JSON.
 */
class MarketingAi
{
    public function __construct(private readonly AiTaskRunner $runner, private readonly BudgetService $budgets) {}

    public function available(): bool
    {
        return $this->runner->available();
    }

    /**
     * @return array<string, mixed>
     *
     * With a project, its approved AI cap is checked first (`ai_budget_reached`, 429) and the call is counted.
     *
     * @throws MarketingException ai_unavailable (503) / ai_blocked (422) / invalid_ai_output (502) / ai_budget_reached (429)
     */
    public function json(Model $actor, string $system, string $input, string $source, ?MarketingProject $project = null): array
    {
        if ($project !== null) {
            $this->budgets->assertAiRoom($project);
        }
        $result = $this->runner->chat([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $input]], $source, $actor, true);
        if ($project !== null && $result['success']) {
            $this->budgets->recordAiCall($project, $actor, $source, mb_strlen($system) + mb_strlen($input), mb_strlen((string) $result['content']));
        }

        if (! $result['success']) {
            if (($result['reason'] ?? null) === AiTaskRunner::REASON_CONTENT_BLOCKED) {
                throw new MarketingException('ai_blocked', 422);
            }
            Log::info('[Marketing AI] '.$source.' failed: '.($result['reason'] ?? '-').' '.($result['message'] ?? ''));

            throw new MarketingException('ai_unavailable', 503);
        }

        $json = self::decode((string) $result['content']);
        if ($json === []) {
            throw new MarketingException('invalid_ai_output', 502);
        }

        return $json;
    }

    /** @return array<string, mixed> */
    public static function decode(string $content): array
    {
        $content = trim((string) preg_replace('/^```(?:json)?|```$/m', '', $content));
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** The language the answer is written in. */
    public static function languageLine(string $locale): string
    {
        return $locale === 'ar'
            ? 'Write every human-readable text value in Modern Standard Arabic.'
            : 'Write every human-readable text value in English.';
    }
}
