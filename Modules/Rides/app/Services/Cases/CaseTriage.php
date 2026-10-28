<?php

namespace Modules\Rides\Services\Cases;

use Modules\Rides\Models\RideCase;
use Modules\Rides\Services\Ai\RideAi;

/**
 * AI-12 — DORR AI's first reading of a complaint or dispute, for the team only: which category it really
 * is, how urgent, a two-line summary, which desk it belongs to, and the evidence points worth checking —
 * from the person's words and the frozen evidence, with no names or phone numbers sent.
 *
 * DORR's own work (nobody's plan pays for it). A suggestion: the case's real category, priority and
 * decision stay the team's; when AI is off or fails the case simply has no reading.
 */
class CaseTriage
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const ROUTES = ['safety', 'payments', 'quality', 'lost_items', 'support'];

    public function __construct(private readonly RideAi $ai) {}

    public function run(RideCase $case): ?array
    {
        $evidence = (array) ($case->evidence ?? []);
        unset($evidence['polyline'], $evidence['planned_polyline'], $evidence['driven_polyline']);
        $input = json_encode([
            'kind' => $case->kind->value,
            'category_chosen' => $case->category,
            'reporter' => $case->reporter_type,
            'words' => mb_substr((string) $case->summary, 0, 1500),
            'disputed_minor' => $case->disputed_minor,
            'evidence' => $evidence,
        ], JSON_UNESCAPED_UNICODE);

        $system = implode("\n", [
            'You triage complaints and disputes about car rides for the DORR support team. Answer with ONE JSON object only:',
            '{"category": string, "priority": "low"|"normal"|"high"|"urgent", "summary": string, "route": "safety"|"payments"|"quality"|"lost_items"|"support", "points": [string]}',
            '- category: the best fitting one of: captain, car, price, payment, safety, privacy, waiting_fee, cancel_fee, no_show_fee, fare, unpaid, other.',
            '- priority: urgent only for a danger to a person (assault, threat, accident, harassment); high for safety worries or money clearly wrong.',
            '- summary: two short sentences in Arabic, neutral, no blame, no names.',
            '- points: up to 4 short Arabic notes on what in the evidence supports or contradicts the claim (times, distances, waiting, the price lines).',
            'Never decide the case and never suggest a refund amount.',
        ]);
        $answer = $this->ai->system($system, (string) $input, 'rides.case_triage');
        if ($answer === null) {
            return null;
        }
        $triage = [
            'category' => is_string($answer['category'] ?? null) ? mb_substr($answer['category'], 0, 32) : null,
            'priority' => in_array($answer['priority'] ?? null, self::PRIORITIES, true) ? $answer['priority'] : null,
            'summary' => is_string($answer['summary'] ?? null) ? mb_substr(trim($answer['summary']), 0, 600) : null,
            'route' => in_array($answer['route'] ?? null, self::ROUTES, true) ? $answer['route'] : null,
            'points' => array_values(array_map(fn ($p) => mb_substr((string) $p, 0, 200), array_slice(array_filter((array) ($answer['points'] ?? []), 'is_string'), 0, 4))),
            'at' => now()->toIso8601String(),
        ];
        $case->forceFill(['ai_triage' => $triage])->save();

        return $triage;
    }
}
