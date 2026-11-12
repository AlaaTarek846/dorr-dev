<?php

namespace Modules\Freelance\Services\Ai;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Modules\AI\Services\AiTaskRunner;
use Modules\Freelance\Exceptions\FreelanceException;

/**
 * DORR AI inside freelance work (FS-401 / FS-501) — through the one AI system, never a model of our own. Every tool here is
 * used by a person and needs a DORR AI plan like every AI tool (ADR-013): a refusal is `freelance_ai_plan_{reason}` (402) with
 * `reason` in the data, which the apps turn into "see the plans". AI only suggests: nothing it writes is saved or sent on its own.
 */
class FreelanceAi
{
    public function __construct(private readonly AiTaskRunner $runner) {}

    /** Whether AI is set up at all (an enabled provider). */
    public function available(): bool
    {
        return $this->runner->available();
    }

    /** Whether this person's plan allows AI now, read-only — to lock a button before it's tapped. */
    public function entitlement(Authenticatable $owner): array
    {
        return $this->runner->entitlement($owner);
    }

    /**
     * A tool the person uses, answered in JSON.
     *
     * @return array<string, mixed>
     */
    public function ask(Authenticatable $owner, string $system, string $input, string $source): array
    {
        // Like the AI website builder: a longer wait for this call only, then the system's default again.
        $previous = config('ai.request_timeout');
        config(['ai.request_timeout' => max((int) $previous, (int) config('freelance.ai.request_timeout', 75))]);
        try {
            $result = $this->runner->chat([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $input]], $source, $owner);
        } finally {
            config(['ai.request_timeout' => $previous]);
        }
        if (! $result['success']) {
            throw $this->failure($result);
        }
        $json = self::json((string) $result['content']);
        if ($json === []) {
            Log::warning('[Freelance AI] '.$source.': the answer was not JSON');

            throw new FreelanceException('ai_unavailable', 503);
        }

        return $json;
    }

    /** The plan's refusal before any call, when the button is locked by the plan. */
    public function refuseUnlessEntitled(Authenticatable $owner): void
    {
        if (! $this->runner->available()) {
            throw new FreelanceException('ai_unavailable', 503);
        }
        $entitlement = $this->entitlement($owner);
        if (! $entitlement['allowed']) {
            $reason = (string) ($entitlement['reason'] ?? 'no_plan');
            throw new FreelanceException('ai_plan_'.$reason, $reason === 'blocked' ? 403 : 402, [], ['reason' => $reason]);
        }
    }

    /** The plan refusal the apps understand, or a plain "AI isn't available now". */
    private function failure(array $result): FreelanceException
    {
        $detail = $result['detail'] ?? [];

        return match ($result['reason'] ?? null) {
            AiTaskRunner::REASON_PLAN => new FreelanceException('ai_plan_'.($detail['usage_reason'] ?? 'no_plan'), 402, [], [
                'reason' => $detail['usage_reason'] ?? 'no_plan',
                'cooldown_seconds_left' => $detail['cooldown_seconds_left'] ?? null,
            ]),
            AiTaskRunner::REASON_OWNER_BLOCKED => new FreelanceException('ai_plan_blocked', 403, [], ['reason' => 'blocked']),
            AiTaskRunner::REASON_CONTENT_BLOCKED => new FreelanceException('ai_message_blocked', 422),
            AiTaskRunner::REASON_UNAVAILABLE => new FreelanceException('ai_unavailable', 503),
            default => (function () use ($result) {
                Log::warning('[Freelance AI] '.($result['provider']?->key ?? '-').': '.($result['message'] ?? ''));

                return new FreelanceException('ai_unavailable', 503);
            })(),
        };
    }

    /** Phone numbers and e-mails are never sent to the model (FS-405): the idea is enough without them. */
    public static function scrub(string $text): string
    {
        $text = (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text);

        return (string) preg_replace('/(?:\+|00)?\d[\d\s\-().]{7,}\d/', '[phone]', $text);
    }

    /** @return array<string, mixed> */
    public static function json(string $content): array
    {
        $content = trim((string) preg_replace('/^```(?:json)?|```$/m', '', $content));
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false) {
            return [];
        }
        $decoded = $end === false || $end < $start ? null : json_decode(substr($content, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            // Small models often slip on the syntax (a missing `]`, a trailing comma): repair it before giving up.
            $decoded = json_decode(self::repair(substr($content, $start)), true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** Closes what the model left open (strings, arrays, objects), in order, and drops trailing commas. */
    private static function repair(string $json): string
    {
        $out = '';
        $stack = [];
        $inString = false;
        $escaped = false;
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $c = $json[$i];
            if ($inString) {
                $out .= $c;
                if ($escaped) {
                    $escaped = false;
                } elseif ($c === '\\') {
                    $escaped = true;
                } elseif ($c === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{' || $c === '[') {
                $stack[] = $c === '{' ? '}' : ']';
            } elseif ($c === '}' || $c === ']') {
                // Close whatever is still open inside before this closer.
                while ($stack !== [] && end($stack) !== $c) {
                    $out = rtrim($out, " \t\r\n,").array_pop($stack);
                }
                if ($stack === []) {
                    break;
                }
                array_pop($stack);
                $out = rtrim($out, " \t\r\n,");
                $out .= $c;
                if ($stack === []) {
                    break;
                }

                continue;
            }
            $out .= $c;
        }
        if ($inString) {
            $out .= '"';
        }
        while ($stack !== []) {
            $out = rtrim($out, " \t\r\n,").array_pop($stack);
        }

        return $out;
    }
}
