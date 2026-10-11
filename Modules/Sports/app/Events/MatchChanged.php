<?php

namespace Modules\Sports\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Sports\Models\SportsMatch;

/**
 * Something people care about changed in a match — what the notifier, the celebrations and the
 * predictions listen to. Each change: {type, …}:
 *  kickoff · goal {side, scorer, minute, own_goal, penalty, home, away} · red_card {side, player, minute}
 *  · half_time · resumed · finished {winner} · postponed · cancelled · suspended · time_changed {old}
 *  · lineups.
 */
class MatchChanged
{
    use Dispatchable;

    /**
     * @param  list<array<string, mixed>>  $changes
     */
    public function __construct(public readonly SportsMatch $match, public readonly array $changes) {}
}
