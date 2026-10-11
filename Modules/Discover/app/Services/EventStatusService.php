<?php

namespace Modules\Discover\Services;

use App\Support\LocaleResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Services\ChatPushNotifier;
use Modules\Discover\Exceptions\DiscoverException;
use Modules\Discover\Models\DiscoverEvent;
use Modules\Discover\Models\DiscoverInterest;

/**
 * An event's status and time (spec 175): confirmed, postponed, cancelled, sold out, ended. Every
 * change is kept, and only the people who said "interested" and asked for updates are told —
 * nobody else (AT-DISC-01).
 */
class EventStatusService
{
    /** Changes nobody needs a notification for. */
    private const SILENT = ['ended'];

    public function __construct(private readonly ChatPushNotifier $push) {}

    public function change(DiscoverEvent $event, string $to, ?string $note, ?CarbonInterface $newStart, ?CarbonInterface $newEnd, string $by): DiscoverEvent
    {
        if (! in_array($to, DiscoverEvent::STATUSES, true)) {
            throw new DiscoverException('invalid_status', 422);
        }
        $from = $event->status;
        $oldStart = $event->starts_at ? CarbonImmutable::instance($event->starts_at) : null;
        $moved = $newStart !== null && ($oldStart === null || ! $oldStart->equalTo($newStart));
        if ($from === $to && ! $moved) {
            return $event;
        }
        if ($newEnd !== null && $newStart !== null && $newEnd->lt($newStart)) {
            throw new DiscoverException('invalid_time', 422);
        }

        DB::transaction(function () use ($event, $from, $to, $note, $oldStart, $moved, $newStart, $newEnd, $by) {
            $event->history()->create([
                'from_status' => $from, 'to_status' => $to, 'note' => $note,
                'old_starts_at' => $moved ? $oldStart : null, 'changed_by' => $by,
            ]);
            $event->status = $to;
            if ($moved) {
                $length = $event->ends_at && $oldStart ? (int) abs($oldStart->diffInSeconds($event->ends_at)) : null;
                $start = CarbonImmutable::instance($newStart)->utc();
                $event->starts_at = $start;
                $event->ends_at = $newEnd !== null ? CarbonImmutable::instance($newEnd)->utc() : ($length !== null ? $start->addSeconds($length) : null);
                $event->dedupe_key = DiscoverEvent::dedupeKey($event->title, (int) $event->city_id, $start->setTimezone($event->timezone)->toDateString());
            }
            $event->last_verified_at = now();
            $event->save();
        });

        if ($event->review_status === 'approved' && ! in_array($to, self::SILENT, true)) {
            $this->notifyInterested($event->refresh(), $to, $moved);
        }

        return $event;
    }

    private function notifyInterested(DiscoverEvent $event, string $to, bool $moved): void
    {
        $owners = DiscoverInterest::query()->where('event_id', $event->id)->where('notify', true)->get(['owner_type', 'owner_id'])
            ->map(fn (DiscoverInterest $i) => [$i->owner_type, (int) $i->owner_id]);
        if ($owners->isEmpty()) {
            return;
        }

        $key = $moved && $to !== 'cancelled' ? 'moved' : $to;
        $when = $event->starts_at ? CarbonImmutable::instance($event->starts_at)->setTimezone($event->timezone) : null;
        $contents = [];
        foreach (LocaleResolver::supported() as $locale) {
            $contents[$locale] = (string) __('discover.push.status.'.$key, ['date' => $when?->format('d/m'), 'time' => $when?->format('H:i')], $locale);
        }

        $this->push->toAccounts(
            $owners,
            array_fill_keys(LocaleResolver::supported(), '📍 '.$event->title),
            $contents,
            ['type' => 'discover', 'event' => 'discover.event.changed', 'event_id' => $event->uuid, 'status' => $to],
        );
    }
}
