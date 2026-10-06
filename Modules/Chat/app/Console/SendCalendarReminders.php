<?php

namespace Modules\Chat\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Models\ChatCalendarItem;
use Modules\Chat\Models\ChatCalendarPreference;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatPrivacySetting;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Services\ChatPushNotifier;
use Modules\Chat\Support\ParticipantType;

/**
 * Smart reminders for my appointments (spec 204), every minute:
 *  - at the times I chose before each one (all-day ones: before 09:00 that day where I am now);
 *  - never twice: the offsets sent are kept for that start, several due at once go as one, and a
 *    message reminder I already have for the same message at about the same time wins (AT-CAL-02);
 *  - during my quiet hours it waits (when I asked it to) and goes when they end, unless the
 *    appointment is already over;
 *  - by the real instant, so travelling never moves it (AT-CAL-01).
 */
class SendCalendarReminders extends Command
{
    protected $signature = 'chat:calendar-reminders';

    protected $description = 'Send DORR Calendar reminders whose time has come';

    public function handle(ChatPushNotifier $push): int
    {
        if (! (ChatSetting::current()->calendar_enabled ?? true)) {
            return self::SUCCESS;
        }

        $now = CarbonImmutable::now('UTC');
        $sent = 0;
        ChatCalendarItem::query()->whereNotNull('reminders')->whereBetween('starts_at', [$now->subDays(2), $now->addDays(8)])->orderBy('id')
            ->chunkById(300, function ($items) use ($push, $now, &$sent) {
                foreach ($items as $item) {
                    $offsets = array_map('intval', $item->reminders ?? []);
                    if ($offsets === []) {
                        continue;
                    }
                    $owner = ParticipantType::modelClassFor($item->owner_type)::query()->find($item->owner_id);
                    if ($owner === null) {
                        continue;
                    }

                    $anchor = $this->anchor($item, $owner->timezone ?? null);
                    $state = $item->reminded ?? [];
                    $done = ($state['anchor'] ?? null) === $anchor->toIso8601String() ? array_map('intval', $state['sent'] ?? []) : [];
                    $due = array_values(array_filter($offsets, fn ($m) => ! in_array($m, $done, true) && $anchor->subMinutes($m)->lte($now)));
                    if ($due === []) {
                        continue;
                    }
                    $mark = fn () => $item->forceFill(['reminded' => ['anchor' => $anchor->toIso8601String(), 'sent' => array_values(array_unique([...$done, ...$due]))]])->save();

                    // Over already (or the server was down): nothing late.
                    if ($anchor->addMinutes(30)->lt($now)) {
                        $mark();

                        continue;
                    }
                    $prefs = ChatCalendarPreference::query()->where('owner_type', $item->owner_type)->where('owner_id', $item->owner_id)->first();
                    $quiet = ChatPrivacySetting::query()->where('owner_type', $item->owner_type)->where('owner_id', $item->owner_id)->first();
                    if (($prefs?->respect_quiet ?? true) && $quiet?->quietOn()) {
                        continue; // waits for the end of the quiet hours
                    }
                    if ($this->messageReminderCovers($item, $anchor->subMinutes(min($due)))) {
                        $mark();

                        continue;
                    }

                    $mark();
                    $push->calendarReminder($item, $owner, (int) round(max(0, $now->diffInMinutes($anchor, false))));
                    $sent++;
                }
            });

        $this->info($sent.' calendar reminder(s) sent.');

        return self::SUCCESS;
    }

    /** When the reminders count back from: the start, or 09:00 of an all-day one where I am now. */
    private function anchor(ChatCalendarItem $item, ?string $zone): CarbonImmutable
    {
        if (! $item->all_day) {
            return CarbonImmutable::parse($item->starts_at)->utc();
        }
        $zone = $zone && in_array($zone, timezone_identifiers_list(), true) ? $zone : (string) config('app.timezone', 'UTC');

        return CarbonImmutable::parse($item->date->toDateString().' 09:00', $zone)->utc();
    }

    /** Is there already a reminder of mine on the same message, within 10 minutes of this one? */
    private function messageReminderCovers(ChatCalendarItem $item, CarbonImmutable $at): bool
    {
        if ($item->message_id === null) {
            return false;
        }
        $mine = ChatParticipant::query()->where('participant_type', $item->owner_type)->where('participant_id', $item->owner_id)->pluck('id');

        return DB::table('chat_message_reminders')->where('message_id', $item->message_id)->whereIn('participant_id', $mine)
            ->whereBetween('remind_at', [$at->subMinutes(10), $at->addMinutes(10)])->exists();
    }
}
