<?php

namespace Modules\Marketing\Services\Plans;

/**
 * The structured shape of each plan type (orchestrator step 07): the AI answers in it, edits are checked against it,
 * and the documents are rendered from it. `normalize()` keeps only known fields, with the right types; `errors()`
 * lists what a usable plan cannot miss.
 */
class PlanSchema
{
    public const BUSINESS_SECTIONS = ['problem', 'solution', 'target_market', 'revenue_model', 'operations', 'team'];

    /** @return array<string, mixed> */
    public static function example(string $type): array
    {
        $common = ['title' => '...', 'summary' => '...', 'assumptions' => ['...'], 'risks' => ['...'], 'sources' => ['S12'], 'confidence_notes' => ['...']];

        return $common + match ($type) {
            'business' => [
                'sections' => array_map(fn ($k) => ['key' => $k, 'title' => '...', 'body' => '...'], self::BUSINESS_SECTIONS),
                'financial' => ['budget_monthly_minor' => null, 'notes' => '...'],
            ],
            'marketing' => [
                'positioning' => '...',
                'segments' => [['name' => '...', 'description' => '...']],
                'channels' => [['channel' => '...', 'rationale' => '...', 'budget_share_pct' => 40]],
                'messages' => ['...'],
                'kpis' => [['name' => '...', 'target' => '...']],
            ],
            'gtm' => [
                'horizon_days' => 90,
                'segments' => ['...'],
                'channels' => ['...'],
                'phases' => [['day_from' => 1, 'day_to' => 30, 'title' => '...', 'tasks' => ['...'], 'kpis' => ['...'], 'go_no_go_criteria' => ['...']]],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(string $type, array $data): array
    {
        $plan = [
            'title' => self::text($data['title'] ?? null, 200),
            'summary' => self::text($data['summary'] ?? null, 4000),
            'assumptions' => self::strings($data['assumptions'] ?? [], 30, 500),
            'risks' => self::strings($data['risks'] ?? [], 30, 500),
            'sources' => array_values(array_unique(array_map('strtoupper', self::strings($data['sources'] ?? [], 60, 20)))),
            'confidence_notes' => self::strings($data['confidence_notes'] ?? [], 20, 500),
        ];

        $plan += match ($type) {
            'business' => [
                'sections' => collect(is_array($data['sections'] ?? null) ? $data['sections'] : [])
                    ->filter(fn ($s) => is_array($s) && in_array($s['key'] ?? null, self::BUSINESS_SECTIONS, true))
                    ->unique('key')->take(count(self::BUSINESS_SECTIONS))
                    ->map(fn ($s) => ['key' => $s['key'], 'title' => self::text($s['title'] ?? null, 200), 'body' => self::text($s['body'] ?? null, 6000)])
                    ->values()->all(),
                'financial' => [
                    'budget_monthly_minor' => self::int($data['financial']['budget_monthly_minor'] ?? null),
                    'notes' => self::text($data['financial']['notes'] ?? null, 2000),
                ],
            ],
            'marketing' => [
                'positioning' => self::text($data['positioning'] ?? null, 2000),
                'segments' => self::rows($data['segments'] ?? [], 12, fn ($r) => ['name' => self::text($r['name'] ?? null, 200), 'description' => self::text($r['description'] ?? null, 1000)], 'name'),
                'channels' => self::rows($data['channels'] ?? [], 15, fn ($r) => [
                    'channel' => self::text($r['channel'] ?? null, 120),
                    'rationale' => self::text($r['rationale'] ?? null, 1000),
                    'budget_share_pct' => self::pct($r['budget_share_pct'] ?? null),
                ], 'channel'),
                'messages' => self::strings($data['messages'] ?? [], 15, 500),
                'kpis' => self::rows($data['kpis'] ?? [], 20, fn ($r) => ['name' => self::text($r['name'] ?? null, 200), 'target' => self::text($r['target'] ?? null, 200)], 'name'),
            ],
            'gtm' => [
                'horizon_days' => max(7, min(365, self::int($data['horizon_days'] ?? null) ?? 90)),
                'segments' => self::strings($data['segments'] ?? [], 12, 200),
                'channels' => self::strings($data['channels'] ?? [], 15, 120),
                'phases' => self::rows($data['phases'] ?? [], 12, fn ($r) => [
                    'day_from' => self::int($r['day_from'] ?? null),
                    'day_to' => self::int($r['day_to'] ?? null),
                    'title' => self::text($r['title'] ?? null, 200),
                    'tasks' => self::strings($r['tasks'] ?? [], 20, 300),
                    'kpis' => self::strings($r['kpis'] ?? [], 15, 200),
                    'go_no_go_criteria' => self::strings($r['go_no_go_criteria'] ?? [], 10, 300),
                ], 'title'),
            ],
        };

        if (isset($data['finance']) && is_array($data['finance'])) {
            $plan['finance'] = $data['finance'];
        }

        return $plan;
    }

    /**
     * What makes a plan unusable (never silently filled in).
     *
     * @param  array<string, mixed>  $plan  normalized
     * @return list<string>
     */
    public static function errors(string $type, array $plan): array
    {
        $errors = [];
        if ($plan['title'] === null) {
            $errors[] = 'title';
        }
        if ($plan['summary'] === null) {
            $errors[] = 'summary';
        }
        match ($type) {
            'business' => count($plan['sections']) < 3 ? $errors[] = 'sections' : null,
            'marketing' => $plan['channels'] === [] ? $errors[] = 'channels' : null,
            'gtm' => $plan['phases'] === [] ? $errors[] = 'phases' : null,
        };

        return $errors;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** @return list<string> */
    private static function strings(mixed $values, int $count, int $max): array
    {
        return collect(is_array($values) ? $values : [])
            ->map(fn ($v) => self::text($v, $max))->filter()->unique()->take($count)->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows, int $count, callable $map, string $required): array
    {
        return collect(is_array($rows) ? $rows : [])->filter(fn ($r) => is_array($r))->map($map)
            ->filter(fn ($r) => $r[$required] !== null)->take($count)->values()->all();
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private static function pct(mixed $value): ?float
    {
        return is_numeric($value) ? max(0, min(100, round((float) $value, 1))) : null;
    }
}
