<?php

namespace Modules\AI\Services\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\AI\Contracts\AiToolInterface;
use Modules\AI\Services\AiChatUsageGuard;

/**
 * A real, working first tool ("كام رسالة/دقيقة باقيلي النهارده؟" - how much
 * of my AI usage is left today): reuses AiChatUsageGuard::evaluate(), the
 * exact same plan/trial/cooldown/quota check AiChatService itself runs
 * before allowing a message, so the answer this tool gives can never
 * disagree with what actually gates the user's next message.
 *
 * Always self-scoped (execute() ignores $arguments entirely and only
 * ever looks at $owner) - there is nothing to authorize beyond "is
 * authenticated", since a user can only ever ask about their own usage.
 */
class AiUsageStatusTool implements AiToolInterface
{
    public function __construct(protected AiChatUsageGuard $usageGuard) {}

    public function name(): string
    {
        return 'usage_status';
    }

    public function description(): string
    {
        return 'Reports the authenticated owner\'s current AI Assistant usage status: whether they can send another message right now, their plan, and how much trial time or cooldown remains.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass,
            'required' => [],
        ];
    }

    public function authorize(Authenticatable $owner): bool
    {
        return true;
    }

    public function execute(Authenticatable $owner, array $arguments): array
    {
        $usage = $this->usageGuard->evaluate($owner);

        return [
            'allowed' => $usage['allowed'],
            'reason' => $usage['reason'],
            'plan_name' => $usage['plan']?->name,
            'plan_is_trial' => $usage['plan']?->is_trial,
            'remaining_seconds' => $usage['remaining_seconds'] ?? null,
            'cooldown_seconds_left' => $usage['cooldown_seconds_left'] ?? null,
        ];
    }
}
