<?php

namespace Modules\AI\Services\Monitoring;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\AI\Models\AiRequest;
use Modules\AI\Models\AiSafetyRule;
use Modules\AI\Models\AiSafetyScan;

/**
 * Writes ai_safety_scans (admin.ai-safety-scans.index): one row for every
 * check AiSafetyGuard::evaluate() runs on a person's text before it goes
 * to a provider - the DORR AI chat (AiChatService) and the AI tools of
 * other modules (AiTaskRunner, chat + image prompts). The guard only
 * checks input text today (no output or file scanning exists), so
 * target_type is "input" and scan_type "harm" (keyword / risk-score
 * rules).
 *
 * findings never hold the text itself - only which rule/policy matched,
 * its condition type and the action - so nothing personal is copied.
 * Failure-safe: logging never breaks the chat.
 */
class AiSafetyScanRecorder
{
    public const TARGET_INPUT = 'input';

    public const SCAN_HARM = 'harm';

    /**
     * @param  array{action: string, rule: ?AiSafetyRule, sanitized: ?string}  $safety  AiSafetyGuard::evaluate()
     */
    public function record(array $safety, ?Authenticatable $owner, ?AiRequest $request = null, string $targetType = self::TARGET_INPUT): void
    {
        try {
            $rule = $safety['rule'] ?? null;

            AiSafetyScan::query()->create([
                'request_id' => $request?->id,
                'owner_type' => $owner?->getMorphClass(),
                'owner_id' => $owner?->getAuthIdentifier(),
                'target_type' => $targetType,
                'scan_type' => self::SCAN_HARM,
                'decision' => $this->decisionFor((string) ($safety['action'] ?? AiSafetyRule::ACTION_ALLOW)),
                'findings' => $rule ? [
                    'rule_id' => $rule->id,
                    'policy_id' => $rule->safety_policy_id,
                    'condition_type' => $rule->condition['type'] ?? null,
                    'action' => $safety['action'],
                ] : null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function decisionFor(string $action): string
    {
        return match ($action) {
            AiSafetyRule::ACTION_BLOCK => AiSafetyScan::DECISION_BLOCKED,
            AiSafetyRule::ACTION_SANITIZE => AiSafetyScan::DECISION_SANITIZED,
            AiSafetyRule::ACTION_ALLOW => AiSafetyScan::DECISION_PASSED,
            default => AiSafetyScan::DECISION_REVIEW_REQUIRED,
        };
    }
}
