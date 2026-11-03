<?php

namespace Modules\Rides\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Modules\Rides\Models\BusBooking;
use Modules\Rides\Models\BusTrip;
use Modules\Rides\Models\DriverHire;
use Modules\Rides\Models\LessonEnrollment;
use Modules\Rides\Models\LessonSession;
use Modules\Rides\Models\MarineBooking;
use Modules\Rides\Services\Bus\BusService;
use Modules\Rides\Services\Hire\DriverHireService;
use Modules\Rides\Services\Notifications\ServiceNotices;
use Modules\Rides\Services\Policy\RidePolicies;

/**
 * The booked services' clock, on the server (the apps never ask on a timer):
 *  - a booking held for its payment and never paid is let go on time, and its owner told;
 *  - a driver request nobody took by its time is expired and refunded;
 *  - shortly before a bus trip, a boat, a driver or a lesson starts, both sides get one reminder
 *    (`services.reminder_before_s`).
 */
class ServicesSweep extends Command
{
    protected $signature = 'rides:services-sweep';

    protected $description = 'Release unpaid service bookings, expire untaken driver requests, and remind people before their bookings start';

    public function handle(BusService $bus, DriverHireService $hire, ServiceNotices $notices, RidePolicies $policies): int
    {
        $bus->releaseLapsed();
        $this->lapse($notices);
        $hire->expireStale();
        $this->remind($notices, $policies, $bus);

        return self::SUCCESS;
    }

    private function lapse(ServiceNotices $notices): void
    {
        $expired = fn ($q) => $q->where('status', 'unpaid')->where('payment_expires_at', '<', now())->limit(100);
        $expired(MarineBooking::query())->with(['listing', 'user'])->get()->each(function (MarineBooking $b) use ($notices) {
            $b->forceFill(['status' => 'lapsed'])->save();
            $notices->lapsed($b->user, 'marine', $b->uuid, __('rides.checkout.marine', ['title' => (string) $b->listing?->title]));
        });
        $expired(DriverHire::query())->with('user')->get()->each(function (DriverHire $h) use ($notices) {
            $h->forceFill(['status' => 'lapsed'])->save();
            $notices->lapsed($h->user, 'hire', $h->uuid, __('rides.checkout.hire'));
        });
        $expired(LessonEnrollment::query())->with(['pack', 'user'])->get()->each(function (LessonEnrollment $e) use ($notices) {
            $e->forceFill(['status' => 'lapsed'])->save();
            $notices->lapsed($e->user, 'lesson', $e->uuid, __('rides.checkout.lessons', ['title' => (string) $e->pack?->title]));
        });
    }

    private function remind(ServiceNotices $notices, RidePolicies $policies, BusService $bus): void
    {
        $lead = fn (?int $countryId) => ($countryId !== null ? $policies->for($countryId, null)->int('services.reminder_before_s') : 0) ?: 3600;
        $soon = now()->addHours(6); // the widest lead an admin would set; each row checks its own

        // A bus leg about to leave: its passengers and its captain.
        BusTrip::query()->where(fn ($q) => $q->where('status', 'scheduled')->whereBetween('depart_at', [now(), $soon])
            ->orWhere(fn ($r) => $r->where('status', 'departed')->whereBetween('return_at', [now(), $soon])))
            ->with('driver')->limit(200)->get()
            ->each(function (BusTrip $trip) use ($notices, $lead, $bus) {
                $leg = $trip->status === 'scheduled' ? 'outbound' : 'return';
                $at = $leg === 'outbound' ? $trip->depart_at : $trip->return_at;
                if ($at->greaterThan(now()->addSeconds($lead($trip->country_id))) || ! $this->once('bus:'.$trip->id.':'.$leg)) {
                    return;
                }
                $time = $this->local($at);
                $what = $bus->routeText($trip);
                $notices->reminder($trip->driver, 'bus', $what, $time, ['trip_uuid' => $trip->uuid]);
                $trip->bookings()->where('status', 'confirmed')->whereIn('trip_type', $leg === 'outbound' ? ['outbound', 'round'] : ['return', 'round'])
                    ->with('user')->get()->each(fn (BusBooking $b) => $notices->reminder($b->user, 'bus', $what, $time, ['booking_uuid' => $b->uuid, 'trip_uuid' => $trip->uuid]));
            });

        // A boat trip / a yacht hire: the guest and the captain.
        MarineBooking::query()->where('status', 'confirmed')->whereBetween('starts_at', [now(), $soon])->with(['listing.driver', 'user'])->limit(200)->get()
            ->each(function (MarineBooking $b) use ($notices, $lead) {
                if ($b->starts_at->greaterThan(now()->addSeconds($lead($b->listing?->country_id))) || ! $this->once('marine:'.$b->id)) {
                    return;
                }
                $time = $this->local($b->starts_at);
                $notices->reminder($b->user, 'marine', (string) $b->listing?->title, $time, ['booking_uuid' => $b->uuid]);
                $notices->reminder($b->listing?->driver, 'marine', (string) $b->listing?->title, $time, ['booking_uuid' => $b->uuid, 'listing_uuid' => $b->listing?->uuid]);
            });

        // A driver booked ahead: the rider and the captain who took it.
        DriverHire::query()->where('status', 'accepted')->whereBetween('starts_at', [now(), $soon])->with(['driver', 'user'])->limit(200)->get()
            ->each(function (DriverHire $h) use ($notices, $lead) {
                if ($h->starts_at->greaterThan(now()->addSeconds($lead($h->country_id))) || ! $this->once('hire:'.$h->id)) {
                    return;
                }
                $time = $this->local($h->starts_at);
                $notices->reminder($h->user, 'hire', __('rides.checkout.hire'), $time, ['hire_uuid' => $h->uuid]);
                $notices->reminder($h->driver, 'hire', (string) $h->pickup_address, $time, ['hire_uuid' => $h->uuid]);
            });

        // A lesson: the learner and the trainer.
        LessonSession::query()->where('status', 'confirmed')->whereBetween('starts_at', [now(), $soon])->with(['enrollment.user', 'enrollment.driver', 'enrollment.pack'])->limit(200)->get()
            ->each(function (LessonSession $s) use ($notices, $lead) {
                $e = $s->enrollment;
                if ($e === null || $s->starts_at->greaterThan(now()->addSeconds($lead($e->pack?->country_id))) || ! $this->once('lesson:'.$s->id)) {
                    return;
                }
                $time = $this->local($s->starts_at);
                $what = (string) $e->pack?->title;
                $notices->reminder($e->user, 'lesson', $what, $time, ['enrollment_uuid' => $e->uuid]);
                $notices->reminder($e->driver, 'lesson', $what, $time, ['session_uuid' => $s->uuid]);
            });
    }

    /** True the first time only (a reminder is sent once). */
    private function once(string $key): bool
    {
        return Cache::add('rides:reminded:'.$key, true, now()->addDay());
    }

    private function local(\Carbon\CarbonInterface $at): string
    {
        return $at->copy()->setTimezone((string) config('rides.default_timezone', 'Asia/Riyadh'))->format('Y-m-d H:i');
    }
}
