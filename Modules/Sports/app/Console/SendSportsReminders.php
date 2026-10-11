<?php

namespace Modules\Sports\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Services\SportsNotifier;

/** Every minute: "kick-off in 15 minutes" for followed matches, at each person's own time. */
class SendSportsReminders extends Command
{
    protected $signature = 'sports:reminders';

    protected $description = 'Remind followers before their matches start';

    public function handle(SportsNotifier $notifier): int
    {
        // Whoever's quiet hours just ended gets what they missed, in one push.
        $digests = $notifier->flushHeld();
        if ($digests > 0) {
            $this->info("digests {$digests}");
        }

        $now = CarbonImmutable::now('UTC');
        $max = (int) (DB::table('sports_preferences')->max('reminder_minutes') ?? 15);
        $teams = DB::table('sports_follows')->where('kind', 'team')->distinct()->pluck('target_id')->all();
        $competitions = DB::table('sports_follows')->where('kind', 'competition')->distinct()->pluck('target_id')->all();
        if ($teams === [] && $competitions === []) {
            return self::SUCCESS;
        }
        $sent = 0;
        SportsMatch::query()->where('status', 'scheduled')
            ->whereBetween('starts_at', [$now, $now->addMinutes(max(15, $max))])
            ->where(fn ($q) => $q->whereIn('home_team_id', $teams ?: [0])->orWhereIn('away_team_id', $teams ?: [0])->orWhereIn('competition_id', $competitions ?: [0]))
            ->get()
            ->each(function (SportsMatch $m) use ($notifier, $now, &$sent) {
                $sent += $notifier->reminder($m, (int) floor($now->diffInMinutes($m->starts_at, true)));
            });
        $this->info("reminded {$sent}");

        return self::SUCCESS;
    }
}
