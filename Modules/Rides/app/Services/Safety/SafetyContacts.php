<?php

namespace Modules\Rides\Services\Safety;

use App\Models\Country;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Driver\Models\Driver;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideEvent;
use Modules\Rides\Models\RideSafetyPreference;
use Modules\Rides\Models\RideTrustedContact;
use Modules\Rides\Services\Lifecycle\RideStateMachine;
use Modules\Sms\Services\Sms\SmsProviderService;
use Modules\Sms\Services\Sms\SmsService;
use Modules\User\Models\User;
use Throwable;

/**
 * Trusted contacts (R-113, R-220, R-221, D-143): when a ride gets its captain, the rider's (and the
 * captain's) contacts set to "every ride" — or "at night" at night — get the ride's live link; they
 * are told "arrived safely" at the end, and alerted when a safety check goes unanswered. A contact
 * who is on DORR gets it in the app; anyone else by SMS (`safety.contacts_sms`). Who was told what
 * is kept as ride events (`contacts_notified`).
 */
class SafetyContacts
{
    public function __construct(
        private readonly RideShareService $shares,
        private readonly NotificationCenter $center,
        private readonly RideStateMachine $machine,
    ) {}

    public static function typeOf(Model $owner): string
    {
        return $owner instanceof Driver ? 'driver' : 'user';
    }

    /** Is it "night" for this ride now (policy hours, the ride's local time)? */
    public static function isNight(Ride $ride): bool
    {
        $hour = (int) now((string) config('rides.default_timezone', 'Asia/Riyadh'))->format('G');
        $from = (int) $ride->policy('safety.night_start_h', 21);
        $to = (int) $ride->policy('safety.night_end_h', 5);

        return $from > $to ? ($hour >= $from || $hour < $to) : ($hour >= $from && $hour < $to);
    }

    public static function preferences(Model $owner): RideSafetyPreference
    {
        return RideSafetyPreference::query()->firstOrNew(['owner_type' => self::typeOf($owner), 'owner_id' => $owner->getKey()]);
    }

    /** Does this rider want the ride code on this ride, whatever the city sets (R-229)? */
    public static function wantsPin(Ride $ride): bool
    {
        $mode = RideSafetyPreference::query()->where('owner_type', 'user')->where('owner_id', $ride->user_id)->value('pin_mode');

        return $mode === 'always' || ($mode === 'night' && self::isNight($ride));
    }

    /** The ride got its captain: the contacts set to "every ride" (or "at night", at night) get the link. */
    public function autoShare(Ride $ride): void
    {
        $ride->loadMissing(['rider', 'driver']);
        foreach (array_filter([$ride->rider, $ride->driver]) as $owner) {
            $contacts = $this->contacts($owner)->filter(fn (RideTrustedContact $c) => $c->share_mode === 'all' || ($c->share_mode === 'night' && self::isNight($ride)));
            if ($contacts->isNotEmpty()) {
                $this->shareWith($ride, $owner, $contacts->all(), true);
            }
        }
    }

    /**
     * "Send to my trusted contacts" from the ride (all of them, or the ones picked).
     *
     * @param  list<int>  $ids
     */
    public function shareNow(Ride $ride, Model $owner, array $ids = []): int
    {
        $contacts = $this->contacts($owner)->when($ids !== [], fn ($c) => $c->whereIn('id', $ids));

        return $this->shareWith($ride, $owner, $contacts->values()->all(), false);
    }

    /** The ride ended: those who got its link (and want it) hear "arrived safely" (R-113). */
    public function arrived(Ride $ride): void
    {
        $ride->loadMissing(['rider', 'driver']);
        $told = RideEvent::query()->where('ride_id', $ride->id)->where('type', 'contacts_notified')->get()
            ->filter(fn (RideEvent $e) => ($e->payload['kind'] ?? null) === 'share')
            ->flatMap(fn (RideEvent $e) => (array) ($e->payload['contact_ids'] ?? []))->unique()->values();
        if ($told->isEmpty()) {
            return;
        }
        $contacts = RideTrustedContact::query()->whereIn('id', $told)->where('notify_arrival', true)->get();
        foreach ($contacts as $contact) {
            $owner = $contact->owner_type === 'driver' ? $ride->driver : $ride->rider;
            $this->send($ride, $contact, 'arrived', ['name' => $this->firstName($owner)]);
        }
        $this->record($ride, 'arrived', $contacts->pluck('id')->all());
    }

    /** A safety check went unanswered: every contact of that person hears it, with the live link. */
    public function alert(Ride $ride, Model $owner): void
    {
        $contacts = $this->contacts($owner);
        if ($contacts->isEmpty()) {
            return;
        }
        $url = $ride->status->isFinal() ? '' : $this->shares->share($ride, $owner)->url();
        foreach ($contacts as $contact) {
            $this->send($ride, $contact, 'alert', ['name' => $this->firstName($owner), 'url' => $url]);
        }
        $this->record($ride, 'alert', $contacts->pluck('id')->all());
    }

    /** @param list<RideTrustedContact> $contacts */
    private function shareWith(Ride $ride, Model $owner, array $contacts, bool $auto): int
    {
        if ($contacts === []) {
            return 0;
        }
        try {
            $url = $this->shares->share($ride, $owner)->url();
        } catch (Throwable) {
            return 0; // sharing is switched off here, or the ride can't be shared now
        }
        foreach ($contacts as $contact) {
            $this->send($ride, $contact, 'share', ['name' => $this->firstName($owner), 'url' => $url]);
        }
        $this->record($ride, 'share', array_map(fn (RideTrustedContact $c) => $c->id, $contacts), $auto);

        return count($contacts);
    }

    /** @return \Illuminate\Support\Collection<int, RideTrustedContact> */
    private function contacts(Model $owner)
    {
        return RideTrustedContact::query()->where('owner_type', self::typeOf($owner))->where('owner_id', $owner->getKey())->orderBy('id')->get();
    }

    /**
     * On DORR → in the app; else an SMS (when allowed and a provider is set for the country).
     *
     * @param  array<string, string>  $vars
     */
    private function send(Ride $ride, RideTrustedContact $contact, string $kind, array $vars): void
    {
        $user = User::query()->where('phone', $contact->phone)->first();
        if ($user !== null) {
            $this->center->send($user, 'ride.contact_'.$kind, 'ride_contact_'.$kind.'_title', 'ride_contact_'.$kind.'_body', $vars + ['url' => ''], [
                'type' => 'ride_share', 'url' => $vars['url'] ?? null,
            ]);

            return;
        }
        if (! (bool) $ride->policy('safety.contacts_sms', true)) {
            return;
        }
        $text = __('notifications.ride_contact_'.$kind.'_sms', $vars + ['url' => '']);
        $countryId = $ride->country_id;
        DB::afterCommit(function () use ($contact, $text, $countryId) {
            try {
                $country = Country::query()->find($countryId);
                $provider = $country ? app(SmsProviderService::class)->activeProvidersForCountry($country)->first() : null;
                if ($provider === null) {
                    return;
                }
                app(SmsService::class)->send($provider, $provider->configuration_plaintext, ['to' => $contact->phone, 'country_id' => $countryId, 'message' => $text]);
            } catch (Throwable $e) {
                Log::warning('[Rides] trusted contact SMS failed: '.$e->getMessage());
            }
        });
    }

    /** @param list<int> $ids */
    private function record(Ride $ride, string $kind, array $ids, bool $auto = false): void
    {
        $this->machine->record($ride, 'contacts_notified', 'system', null, [], ['kind' => $kind, 'contact_ids' => $ids, 'auto' => $auto]);
    }

    private function firstName(?Model $owner): string
    {
        return Str::before(trim((string) ($owner?->name ?? '')), ' ');
    }
}
