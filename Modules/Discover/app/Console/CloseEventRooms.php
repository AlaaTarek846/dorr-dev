<?php

namespace Modules\Discover\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Chat\Services\MessageService;
use Modules\Discover\Models\DiscoverEventRoom;
use Modules\Discover\Models\DiscoverSetting;

/**
 * An event's room after the event (spec 179): some hours later (the admin's setting) only the
 * group's admins can write — the photos and plans stay, the group just quiets down.
 */
class CloseEventRooms extends Command
{
    protected $signature = 'discover:close-rooms';

    protected $description = 'Make event rooms read-only some hours after their event';

    public function handle(MessageService $messages): int
    {
        $hours = max(0, DiscoverSetting::current()->room_close_hours);
        $closed = 0;

        DiscoverEventRoom::query()->whereNull('closed_at')->with(['event', 'conversation.group'])->orderBy('id')
            ->chunkById(200, function ($rooms) use ($hours, $messages, &$closed) {
                foreach ($rooms as $room) {
                    $end = $room->event?->ends_at ?? $room->event?->starts_at;
                    if ($room->event !== null && $room->event->status !== 'ended' && ($end === null || CarbonImmutable::instance($end)->addHours($hours)->isFuture())) {
                        continue;
                    }
                    if (($group = $room->conversation?->group) !== null) {
                        $group->update(['only_admins_send' => true]);
                        $messages->system($room->conversation, null, 'event_room_closed');
                    }
                    $room->update(['closed_at' => now()]);
                    $closed++;
                }
            });

        $this->info("Closed {$closed} event room(s).");

        return self::SUCCESS;
    }
}
