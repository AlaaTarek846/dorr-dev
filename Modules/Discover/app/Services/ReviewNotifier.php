<?php

namespace Modules\Discover\Services;

use App\Support\LocaleResolver;
use Modules\Chat\Services\ChatPushNotifier;
use Modules\Discover\Models\DiscoverEvent;
use Modules\Discover\Models\DiscoverOrganizer;

/**
 * Tells an organizer the admin's answer, so they don't have to keep opening their page:
 * their request to be one (verified · rejected · suspended), and each event they sent
 * (published · rejected), with the admin's note when there is one.
 */
class ReviewNotifier
{
    public function __construct(private readonly ChatPushNotifier $push) {}

    public function organizer(DiscoverOrganizer $organizer, string $from): void
    {
        $to = (string) $organizer->status;
        if ($to === $from || ! in_array($to, ['verified', 'rejected', 'suspended'], true)) {
            return;
        }

        $this->send($organizer, 'organizer_'.$to, $organizer->review_note, ['event' => 'discover.organizer.reviewed', 'status' => $to]);
    }

    public function event(DiscoverEvent $event, string $from): void
    {
        $to = (string) $event->review_status;
        $organizer = $event->organizer;
        if ($organizer === null || $to === $from || ! in_array($to, ['approved', 'rejected'], true)) {
            return;
        }

        $this->send($organizer, 'event_'.$to, $event->review_note, ['event' => 'discover.event.reviewed', 'event_id' => $event->uuid, 'status' => $to], $event->title);
    }

    /**
     * @param  array<string, string>  $data
     */
    private function send(DiscoverOrganizer $organizer, string $key, ?string $note, array $data, ?string $title = null): void
    {
        $headings = [];
        $contents = [];
        foreach (LocaleResolver::supported() as $locale) {
            $headings[$locale] = (string) __("discover.push.review.{$key}_title", ['title' => $title], $locale);
            $contents[$locale] = filled($note)
                ? (string) __('discover.push.review.with_note', ['note' => $note], $locale)
                : (string) __("discover.push.review.{$key}_body", [], $locale);
        }

        $this->push->toAccounts([[$organizer->owner_type, (int) $organizer->owner_id]], $headings, $contents, ['type' => 'discover'] + $data);
    }
}
