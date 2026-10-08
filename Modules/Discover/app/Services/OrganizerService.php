<?php

namespace Modules\Discover\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Support\ParticipantType;
use Modules\Discover\Exceptions\DiscoverException;
use Modules\Discover\Models\DiscoverCity;
use Modules\Discover\Models\DiscoverEvent;
use Modules\Discover\Models\DiscoverOrganizer;

/**
 * Organizers (spec 180): anyone may ask to be one and submit events, but nothing goes public
 * until the admin approved it, and an event is shown as verified only when its organizer is
 * (AT-DISC-02). The same event twice is refused (dedupe by title, city and day).
 */
class OrganizerService
{
    public const FIELDS = ['title', 'description', 'language', 'category_id', 'venue', 'address', 'lat', 'lng', 'is_free', 'price_text', 'source_url', 'booking_url', 'family_friendly'];

    public function __construct(
        private readonly DiscoverService $discover,
        private readonly EventStatusService $statuses,
    ) {}

    public function mine(Model $me): ?DiscoverOrganizer
    {
        return DiscoverOrganizer::query()->ownedBy($me)->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function presentOrganizer(?DiscoverOrganizer $o): ?array
    {
        return $o === null ? null : [
            'id' => $o->id, 'name' => $o->name, 'about' => $o->about, 'website' => $o->website, 'phone' => $o->phone, 'email' => $o->email,
            'status' => $o->status, 'review_note' => $o->review_note, 'verified' => $o->isVerified(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(Model $me, array $data): DiscoverOrganizer
    {
        $this->assertSubmissions();
        $organizer = $this->mine($me);
        if ($organizer?->status === 'suspended') {
            throw new DiscoverException('organizer_suspended', 403);
        }
        $fields = Arr::only($data, ['name', 'about', 'website', 'phone', 'email']);

        if ($organizer === null) {
            return DiscoverOrganizer::query()->create($fields + ['owner_type' => ParticipantType::aliasFor($me), 'owner_id' => $me->getKey(), 'status' => 'pending']);
        }

        $organizer->fill($fields);
        // A rejected one asks again; a verified one with a new name is a new identity to check.
        if ($organizer->status === 'rejected' || ($organizer->isVerified() && $organizer->isDirty('name'))) {
            $organizer->status = 'pending';
            $organizer->verified_at = null;
        }
        $organizer->save();

        return $organizer;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(Model $me, array $data, ?UploadedFile $cover): DiscoverEvent
    {
        $this->assertSubmissions();
        $organizer = $this->organizerOrFail($me);
        $fields = self::fields($data);
        self::assertNotDuplicate($fields['dedupe_key']);
        $auto = $organizer->isVerified() && $this->discover->settings()->auto_publish_verified;

        return DB::transaction(function () use ($fields, $organizer, $auto, $cover) {
            $event = DiscoverEvent::query()->create($fields + [
                'uuid' => (string) Str::uuid(),
                'organizer_id' => $organizer->id,
                'source' => 'organizer',
                'status' => 'confirmed',
                // A verified organizer's go live (when the admin allows it); the rest wait for review.
                'review_status' => $auto ? 'approved' : 'pending',
                'last_verified_at' => $auto ? now() : null,
            ]);
            if ($cover !== null) {
                $event->setSingleMedia(DiscoverEvent::COVER, $cover);
            }

            return $event->refresh();
        });
    }

    /**
     * Editing my event: a new time on a public event is a change the interested hear about; an
     * unverified organizer's edit goes back to review.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Model $me, DiscoverEvent $event, array $data, ?UploadedFile $cover): DiscoverEvent
    {
        $organizer = $this->organizerOrFail($me);
        $this->assertOwn($organizer, $event);
        $fields = self::fields($data, $event);
        self::assertNotDuplicate($fields['dedupe_key'], $event->id);

        return DB::transaction(function () use ($organizer, $event, $fields, $cover) {
            if (isset($fields['starts_at']) && $event->review_status === 'approved' && $event->starts_at !== null && ! $fields['starts_at']->equalTo($event->starts_at)) {
                $this->statuses->change($event, $event->status === 'postponed' ? 'confirmed' : $event->status, null, $fields['starts_at'], $fields['ends_at'] ?? null, 'organizer');
                unset($fields['starts_at'], $fields['ends_at']);
            }
            $event->fill($fields);
            if (! ($organizer->isVerified() && $this->discover->settings()->auto_publish_verified)) {
                $event->review_status = 'pending';
            }
            $event->save();
            if ($cover !== null) {
                $event->setSingleMedia(DiscoverEvent::COVER, $cover);
            }

            return $event->refresh();
        });
    }

    public function changeStatus(Model $me, DiscoverEvent $event, string $to, ?string $note, ?string $newStart): DiscoverEvent
    {
        $organizer = $this->organizerOrFail($me);
        $this->assertOwn($organizer, $event);

        return $this->statuses->change($event, $to, $note, $newStart ? CarbonImmutable::parse($newStart, $event->timezone) : null, null, 'organizer');
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Model $me, string $zone): array
    {
        $organizer = $this->mine($me);
        $events = $organizer === null ? collect() : $organizer->events()->with(['category.translations', 'city.translations', 'country', 'organizer', 'media'])->latest('id')->limit(100)->get();

        return [
            'organizer' => $this->presentOrganizer($organizer),
            'can_submit' => (bool) $this->discover->settings()->submissions_enabled,
            'events' => $events->map(fn (DiscoverEvent $e) => $this->discover->present($e, $zone) + ['review_status' => $e->review_status, 'review_note' => $e->review_note])->values()->all(),
        ];
    }

    /**
     * The event's own fields, its time read in the city's zone (169, 173): "20:00" in Dubai is 20:00 there.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fields(array $data, ?DiscoverEvent $existing = null): array
    {
        $city = DiscoverCity::query()->find((int) ($data['city_id'] ?? $existing?->city_id)) ?? throw DiscoverException::notFound();
        $zone = $city->timezone;
        $out = Arr::only($data, self::FIELDS);
        $out['city_id'] = $city->id;
        $out['country_id'] = $city->country_id;
        $out['timezone'] = $zone;

        if (! empty($data['starts_at'])) {
            $out['starts_at'] = CarbonImmutable::parse($data['starts_at'], $zone)->utc();
        } elseif ($existing?->starts_at !== null && $existing->timezone !== $zone) {
            // Moved to a city in another zone: the same wall-clock time there.
            $out['starts_at'] = CarbonImmutable::parse(CarbonImmutable::instance($existing->starts_at)->setTimezone($existing->timezone)->format('Y-m-d H:i:s'), $zone)->utc();
        }
        if (array_key_exists('ends_at', $data)) {
            $out['ends_at'] = ! empty($data['ends_at']) ? CarbonImmutable::parse($data['ends_at'], $zone)->utc() : null;
        }
        if (! empty($out['is_free'])) {
            $out['price_text'] = null;
        }

        $start = $out['starts_at'] ?? ($existing?->starts_at ? CarbonImmutable::instance($existing->starts_at) : null);
        $end = array_key_exists('ends_at', $out) ? $out['ends_at'] : ($existing?->ends_at ? CarbonImmutable::instance($existing->ends_at) : null);
        if ($start === null) {
            throw new DiscoverException('invalid_time', 422);
        }
        if ($end !== null && $end->lt($start)) {
            throw new DiscoverException('invalid_time', 422);
        }
        $out['dedupe_key'] = DiscoverEvent::dedupeKey((string) ($out['title'] ?? $existing?->title), $city->id, $start->setTimezone($zone)->toDateString());

        return $out;
    }

    public static function assertNotDuplicate(string $key, ?int $ignoreId = null): void
    {
        $existing = DiscoverEvent::query()->where('dedupe_key', $key)->where('review_status', '!=', 'rejected')
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->first();
        if ($existing !== null) {
            throw new DiscoverException('duplicate', 409, [], ['event_id' => $existing->uuid]);
        }
    }

    private function assertSubmissions(): void
    {
        if (! $this->discover->settings()->submissions_enabled) {
            throw new DiscoverException('submissions_off', 403);
        }
    }

    private function organizerOrFail(Model $me): DiscoverOrganizer
    {
        $organizer = $this->mine($me);
        if ($organizer === null || $organizer->status === 'rejected') {
            throw new DiscoverException('not_organizer', 403);
        }
        if ($organizer->status === 'suspended') {
            throw new DiscoverException('organizer_suspended', 403);
        }

        return $organizer;
    }

    private function assertOwn(DiscoverOrganizer $organizer, DiscoverEvent $event): void
    {
        if ((int) $event->organizer_id !== (int) $organizer->id) {
            throw DiscoverException::notFound();
        }
    }
}
