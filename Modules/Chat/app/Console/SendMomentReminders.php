<?php

namespace Modules\Chat\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Modules\Chat\Models\ChatMomentPreference;
use Modules\Chat\Models\ChatPersonalMoment;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Services\ChatPushNotifier;
use Modules\Chat\Services\MomentService;
use Modules\Chat\Support\ParticipantType;

/**
 * Reminders for my own dates (DORR Moments): from 9 in the morning in my own time zone, once N
 * days before ("Sara's birthday is tomorrow") and once on the day ("…is today — send a card").
 * Nothing when Moments are off for me or for everyone. Scheduled every ten minutes.
 */
class SendMomentReminders extends Command
{
    protected $signature = 'chat:moment-reminders';

    protected $description = 'Remind people of their own dates (birthdays, anniversaries…) — before and on the day';

    public const HOUR = 9;

    public function handle(ChatPushNotifier $push, MomentService $moments): int
    {
        if (! ChatSetting::current()->moments_enabled) {
            return self::SUCCESS;
        }

        // Every (month, day) that could be "today" or within the reminder window anywhere on earth.
        $utc = CarbonImmutable::now('UTC');
        $pairs = collect(range(-1, 31))->map(fn ($i) => $utc->addDays($i))->map(fn ($d) => [$d->month, $d->day])->unique(fn ($p) => $p[0].'-'.$p[1]);
        if ($pairs->contains(fn ($p) => $p === [2, 28])) {
            $pairs->push([2, 29]); // 29 February falls on the 28th in other years
        }

        $sent = 0;
        ChatPersonalMoment::query()
            ->where(fn (Builder $q) => $pairs->each(fn ($p) => $q->orWhere(fn ($q) => $q->where('month', $p[0])->where('day', $p[1]))))
            ->orderBy('id')
            ->chunkById(300, function ($rows) use ($push, $moments, &$sent) {
                $owners = [];
                foreach ($rows->groupBy('owner_type') as $type => $group) {
                    $class = ParticipantType::modelClassFor($type);
                    $owners[$type] = $class::query()->whereIn('id', $group->pluck('owner_id')->unique())->get()->keyBy('id');
                }
                $off = ChatMomentPreference::query()->whereIn('owner_id', $rows->pluck('owner_id')->unique())->where('enabled', false)->get()
                    ->map(fn ($p) => $p->owner_type.':'.$p->owner_id)->flip();

                foreach ($rows as $row) {
                    $owner = $owners[$row->owner_type][$row->owner_id] ?? null;
                    if ($owner === null || $off->has($row->owner_type.':'.$row->owner_id)) {
                        continue;
                    }

                    $zone = $this->zone($owner->timezone ?? null);
                    $local = CarbonImmutable::now($zone);
                    if ($local->hour < self::HOUR) {
                        continue;
                    }
                    $today = $local->startOfDay();
                    $next = $moments->nextYearly($row->month, $row->day, $today);
                    $days = (int) $today->diffInDays($next);
                    $date = $next->toDateString();

                    if ($days === 0 && $row->reminded_day_for?->toDateString() !== $date) {
                        $push->momentReminder($owner, $row, 0);
                        $row->forceFill(['reminded_day_for' => $date])->save();
                        $sent++;
                    } elseif ($days > 0 && $days <= $row->remind_days_before && $row->reminded_before_for?->toDateString() !== $date) {
                        $push->momentReminder($owner, $row, $days);
                        $row->forceFill(['reminded_before_for' => $date])->save();
                        $sent++;
                    }
                }
            });

        $this->info($sent.' reminder(s) sent.');

        return self::SUCCESS;
    }

    private function zone(?string $zone): string
    {
        return $zone && in_array($zone, timezone_identifiers_list(), true) ? $zone : (string) config('app.timezone', 'UTC');
    }
}
