<?php

namespace Modules\Sports\Listeners;

use Modules\Sports\Events\MatchChanged;
use Modules\Sports\Models\SportsContest;
use Modules\Sports\Services\ContestService;
use Modules\Sports\Services\PredictionService;
use Throwable;

/** A match is over (or off): score its predictions, and settle its single-match contests. */
class SettlePredictions
{
    public function __construct(private readonly PredictionService $predictions, private readonly ContestService $contests) {}

    public function handle(MatchChanged $event): void
    {
        if (! collect($event->changes)->pluck('type')->intersect(['finished', 'cancelled', 'postponed'])->isNotEmpty()) {
            return;
        }
        $this->predictions->settle($event->match);
        SportsContest::query()->open()->where('scope', 'match')->where('match_id', $event->match->id)->get()->each(function ($c) {
            try {
                $this->contests->settle($c);
            } catch (Throwable) {
                // settled later by sports:contests
            }
        });
    }
}
