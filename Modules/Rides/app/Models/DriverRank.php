<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Rides\Models\Concerns\LocalizedJson;

/** A captain rank (D-239..D-243, D-252): its thresholds come from here, never from the apps. */
class DriverRank extends Model
{
    use LocalizedJson;

    /** The thresholds, by the metric they read: [column, metric, minimum?]. */
    public const RULES = [
        ['min_trips', 'trips', true],
        ['min_rating', 'rating', true],
        ['min_cleanliness', 'cleanliness', true],
        ['min_behavior', 'behavior', true],
        ['min_commitment_bp', 'commitment_bp', true],
        ['min_completion_bp', 'completion_bp', true],
        ['max_cancel_bp', 'cancel_bp', false],
        ['max_warnings', 'warnings', false],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'name' => 'array', 'benefits' => 'array', 'probation' => 'boolean', 'status' => 'boolean',
            'min_trips' => 'integer', 'min_rating' => 'integer', 'min_cleanliness' => 'integer', 'min_behavior' => 'integer',
            'min_commitment_bp' => 'integer', 'min_completion_bp' => 'integer', 'max_cancel_bp' => 'integer', 'max_warnings' => 'integer',
        ];
    }

    /** @return list<string> */
    public function benefitList(): array
    {
        $b = (array) ($this->benefits ?? []);

        return array_values((array) ($b[app()->getLocale()] ?? $b['ar'] ?? $b['en'] ?? []));
    }

    /**
     * What these numbers still miss for this rank.
     *
     * @param  array<string, int>  $metrics
     * @return list<array{metric: string, need: int, have: int, min: bool}>
     */
    public function missing(array $metrics): array
    {
        $out = [];
        foreach (self::RULES as [$column, $metric, $min]) {
            $need = (int) $this->{$column};
            $have = (int) ($metrics[$metric] ?? 0);
            // A minimum of 0 / a maximum left open asks for nothing; a rating not given yet doesn't block a rating rule of 0.
            if ($min ? ($need > 0 && $have < $need) : ($have > $need)) {
                $out[] = ['metric' => $metric, 'need' => $need, 'have' => $have, 'min' => $min];
            }
        }

        return $out;
    }
}
