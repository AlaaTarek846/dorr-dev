<?php

namespace Modules\Rides\Services\Lessons;

use App\Models\Country;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\LessonEnrollment;
use Modules\Rides\Models\LessonPack;
use Modules\Rides\Models\LessonSession;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\Rides\Services\Policy\RidePolicyData;
use Modules\Rides\Support\Geo;
use Modules\User\Models\User;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\FinancialLedgerService;
use Modules\Wallet\Services\WalletService;

/**
 * Driving lessons (docs/rides_tasks.md O-26b).
 *
 *  - A trainer (an active captain) publishes lesson packs: N lessons of M minutes, automatic or manual,
 *    the price of the pack, the area he teaches in, and whether he brings a training car.
 *  - A learner finds packs near them (`lessons.search_radius_m`) and buys one from the wallet
 *    (non-withdrawable money first).
 *  - They book each lesson: a time and where to be picked up. The trainer accepts or declines; the learner
 *    may cancel a lesson up to `lessons.free_cancel_before_s` before it.
 *  - When the trainer marks a lesson done he's paid its share less `lessons.commission_bp`.
 *  - A learner who stops gets the lessons never taken back; the money goes to the non-withdrawable balance
 *    unless nothing was taken yet, when the whole payment is simply reversed.
 */
class DrivingLessonService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly NotificationCenter $notifications,
        private readonly RidePolicies $policies,
        private readonly RideFeatures $features,
        private readonly CityResolver $cities,
        private readonly FinancialLedgerService $ledger,
    ) {}

    // ---------------------------------------------------------------- the trainer

    /** @param array<string, mixed> $data */
    public function publish(Driver $driver, array $data): LessonPack
    {
        $this->assertTrainer($driver);
        $country = Country::query()->find($driver->country_id) ?? throw new RidesException('lessons_no_country', 422);

        return LessonPack::query()->create([
            'driver_id' => $driver->id, 'country_id' => $country->id, 'currency_id' => $country->currency_id,
            'title' => $data['title'], 'description' => $data['description'] ?? null,
            'transmission' => $data['transmission'], 'lessons' => (int) $data['lessons'], 'minutes_per_lesson' => (int) $data['minutes_per_lesson'],
            'price_minor' => (int) $data['price_minor'], 'own_car' => (bool) ($data['own_car'] ?? true),
            'area_lat' => $data['area']['lat'], 'area_lng' => $data['area']['lng'], 'area_address' => $data['area']['address'],
        ])->refresh();
    }

    /**
     * O-62: edit a pack. With learners in it only the words and the area change (the price and the lessons
     * they bought stay as they were); without, everything.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Driver $driver, LessonPack $pack, array $data): LessonPack
    {
        $this->assertOwner($driver, $pack->driver_id);
        $taken = $pack->enrollments()->where('status', 'active')->exists();
        $locked = ['transmission', 'lessons', 'minutes_per_lesson', 'price_minor'];
        if ($taken && collect($locked)->contains(fn (string $k) => array_key_exists($k, $data) && $data[$k] != $pack->{$k})) {
            throw new RidesException('lessons_pack_has_learners', 409);
        }
        $fill = array_intersect_key($data, array_flip(['title', 'description', 'transmission', 'lessons', 'minutes_per_lesson', 'price_minor', 'own_car']));
        if (isset($data['area'])) {
            $fill += ['area_lat' => $data['area']['lat'], 'area_lng' => $data['area']['lng'], 'area_address' => $data['area']['address']];
        }
        $pack->forceFill($fill)->save();

        return $pack->refresh();
    }

    /** O-62: remove a pack — gone if nobody ever bought it; else archived (off the search; learners carry on). */
    public function remove(Driver $driver, LessonPack $pack): ?LessonPack
    {
        $this->assertOwner($driver, $pack->driver_id);
        if (! $pack->enrollments()->exists()) {
            $pack->delete();

            return null;
        }
        $pack->forceFill(['status' => 'archived'])->save();

        return $pack->refresh();
    }

    public function setStatus(Driver $driver, LessonPack $pack, string $status): LessonPack
    {
        $this->assertOwner($driver, $pack->driver_id);
        $pack->forceFill(['status' => $status])->save();

        return $pack->refresh();
    }

    /** @return Collection<int, LessonPack> */
    public function packsOf(Driver $driver): Collection
    {
        return LessonPack::query()->where('driver_id', $driver->id)->where('status', '!=', 'archived')->withCount(['enrollments' => fn ($q) => $q->where('status', 'active')])->latest('id')->get();
    }

    /** @return Collection<int, LessonSession> the lessons asked for and coming up, soonest first */
    public function sessionsOf(Driver $driver): Collection
    {
        return LessonSession::query()->whereHas('enrollment', fn ($q) => $q->where('driver_id', $driver->id))
            ->whereIn('status', ['requested', 'confirmed'])->with(['enrollment.user', 'enrollment.pack'])->orderBy('starts_at')->limit(100)->get();
    }

    public function answer(Driver $driver, LessonSession $session, bool $approve): LessonSession
    {
        $enrollment = $session->enrollment;
        $this->assertOwner($driver, $enrollment->driver_id);
        if ($session->status !== 'requested') {
            throw new RidesException('lessons_not_editable', 409);
        }
        if ($approve && LessonSession::query()->where('id', '!=', $session->id)->where('status', 'confirmed')
            ->whereHas('enrollment', fn ($q) => $q->where('driver_id', $driver->id))
            ->whereBetween('starts_at', [$session->starts_at->copy()->subMinutes($enrollment->pack->minutes_per_lesson), $session->starts_at->copy()->addMinutes($enrollment->pack->minutes_per_lesson)])
            ->exists()) {
            throw new RidesException('lessons_trainer_busy', 409);
        }
        $session->forceFill(['status' => $approve ? 'confirmed' : 'declined'])->save();
        $this->notifications->send($enrollment->user, $approve ? 'lesson.confirmed' : 'lesson.declined',
            $approve ? 'lesson_confirmed_title' : 'lesson_declined_title', $approve ? 'lesson_confirmed_body' : 'lesson_declined_body', [
                'number' => $session->number, 'time' => $this->local($session->starts_at),
            ], ['type' => 'lesson', 'enrollment_uuid' => $enrollment->uuid]);

        return $session->refresh();
    }

    /** The lesson was given: the trainer is paid its share. */
    public function done(Driver $driver, LessonSession $session): LessonSession
    {
        $enrollment = $session->enrollment;
        $this->assertOwner($driver, $enrollment->driver_id);
        if ($session->status !== 'confirmed' || $session->starts_at->isFuture()) {
            throw new RidesException('lessons_not_editable', 409);
        }
        DB::transaction(function () use ($session, $enrollment) {
            $enrollment = LessonEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $session->forceFill(['status' => 'done', 'done_at' => now()])->save();
            $number = $enrollment->lessons_done + 1;
            $enrollment->forceFill(['lessons_done' => $number, 'status' => $number >= $enrollment->lessons_total ? 'completed' : 'active'])->save();
            $earned = $this->payTrainer($enrollment, $enrollment->pack->lessonShare($number), $session);
            DB::afterCommit(fn () => app(\Modules\Rides\Services\Notifications\ServiceNotices::class)->earning(
                $enrollment->driver, 'lesson', $earned, $enrollment->pack->currency_id, $enrollment->pack->title, ['enrollment_uuid' => $enrollment->uuid],
            ));
        });
        $this->notifications->send($enrollment->user, 'lesson.done', 'lesson_done_title', 'lesson_done_body', [
            'number' => $session->number, 'left' => max(0, $enrollment->lessons_total - $enrollment->refresh()->lessons_done),
        ], ['type' => 'lesson', 'enrollment_uuid' => $enrollment->uuid]);

        return $session->refresh();
    }

    // ---------------------------------------------------------------- the learner

    /** @return Collection<int, LessonPack> */
    public function browse(User $learner, float $lat, float $lng, ?string $transmission): Collection
    {
        [$country, $policy] = $this->placeOf($learner, $lat, $lng);
        $radius = $policy->int('lessons.search_radius_m') ?: 40000;

        return LessonPack::query()->where('country_id', $country->id)->where('status', 'active')
            ->when($transmission, fn ($q, $t) => $q->where('transmission', $t))
            ->with(['driver', 'currency'])->limit(200)->get()
            ->filter(fn (LessonPack $p) => Geo::metres($lat, $lng, $p->area_lat, $p->area_lng) <= $radius)
            ->sortBy(fn (LessonPack $p) => $p->price_minor / max(1, $p->lessons))
            ->values();
    }

    public function enroll(User $learner, LessonPack $pack, ?string $key): LessonEnrollment
    {
        if ($key !== null && ($done = LessonEnrollment::query()->where('user_id', $learner->id)->where('idempotency_key', $key)->first()) !== null) {
            return $done;
        }
        if ($pack->status !== 'active') {
            throw new RidesException('lessons_pack_closed', 409);
        }
        // O-67: held «unpaid» while the app's payment screen pays it (coupon, top-up).
        $enrollment = LessonEnrollment::query()->create([
            'lesson_pack_id' => $pack->id, 'user_id' => $learner->id, 'driver_id' => $pack->driver_id,
            'lessons_total' => $pack->lessons, 'amount_minor' => $pack->price_minor, 'currency_id' => $pack->currency_id, 'idempotency_key' => $key,
            'status' => 'unpaid', 'payment_expires_at' => now()->addMinutes(\Modules\Rides\Services\Bus\BusService::HOLD_MINUTES),
        ]);

        return $enrollment->refresh();
    }

    /** O-67: paid — the rest (after the coupon) is taken now; the pack is the learner's and the trainer hears of it. */
    public function confirmPaid(LessonEnrollment $enrollment, int $payable, int $discount): LessonEnrollment
    {
        if ($enrollment->status !== 'unpaid') {
            return $enrollment;
        }
        if ($enrollment->payment_expires_at !== null && $enrollment->payment_expires_at->isPast()) {
            throw new RidesException('booking_lapsed', 409);
        }
        $pack = $enrollment->pack;
        $learner = $enrollment->user()->firstOrFail();
        $enrollment->forceFill([
            'status' => 'active', 'payment_expires_at' => null, 'discount_minor' => $discount,
            'wallet_transaction_ids' => $payable > 0 ? $this->charge($learner, $pack, $enrollment, $payable) : [],
        ])->save();
        $this->notifications->send($pack->driver, 'lesson.enrolled', 'lesson_enrolled_title', 'lesson_enrolled_body', [
            'name' => strtok((string) $learner->name, ' ') ?: '', 'title' => $pack->title,
        ], ['type' => 'lesson', 'pack_uuid' => $pack->uuid]);
        $this->notifications->send($learner, 'lesson.purchased', 'lesson_purchased_title', 'lesson_purchased_body', [
            'title' => $pack->title, 'lessons' => $enrollment->lessons_total,
        ], ['type' => 'lesson', 'enrollment_uuid' => $enrollment->uuid]);

        return $enrollment->refresh();
    }

    /** @param array{starts_at: string, pickup: array{lat: float, lng: float, address: string}} $data */
    public function book(User $learner, LessonEnrollment $enrollment, array $data): LessonSession
    {
        if ($enrollment->user_id !== $learner->id || $enrollment->status !== 'active') {
            throw new RidesException('lessons_not_editable', 409);
        }
        if ($enrollment->lessonsLeft() <= 0) {
            throw new RidesException('lessons_none_left', 409);
        }
        $at = Carbon::parse($data['starts_at']);
        if (! $at->isFuture()) {
            throw new RidesException('bus_time_past', 422);
        }
        $session = LessonSession::query()->create([
            'lesson_enrollment_id' => $enrollment->id,
            'number' => $enrollment->sessions()->whereIn('status', ['requested', 'confirmed', 'done'])->count() + 1,
            'starts_at' => $at, 'pickup_lat' => $data['pickup']['lat'], 'pickup_lng' => $data['pickup']['lng'], 'pickup_address' => $data['pickup']['address'],
        ]);
        $this->notifications->send($enrollment->driver, 'lesson.requested', 'lesson_requested_title', 'lesson_requested_body', [
            'name' => strtok((string) $learner->name, ' ') ?: '', 'number' => $session->number, 'time' => $this->local($at),
        ], ['type' => 'lesson', 'session_uuid' => $session->uuid]);

        return $session->refresh();
    }

    public function cancelSession(User $learner, LessonSession $session): LessonSession
    {
        $enrollment = $session->enrollment;
        if ($enrollment->user_id !== $learner->id || ! in_array($session->status, ['requested', 'confirmed'], true)) {
            throw new RidesException('lessons_not_editable', 409);
        }
        $window = $this->policies->for($enrollment->pack->country_id, null)->int('lessons.free_cancel_before_s') ?: 7200;
        if ($session->status === 'confirmed' && now()->addSeconds($window)->greaterThan($session->starts_at)) {
            throw new RidesException('lessons_too_late', 409, ['hours' => max(1, intdiv($window, 3600))]);
        }
        $session->forceFill(['status' => 'canceled'])->save();
        $this->notifications->send($enrollment->driver, 'lesson.canceled', 'lesson_canceled_title', 'lesson_canceled_body', [
            'number' => $session->number, 'time' => $this->local($session->starts_at),
        ], ['type' => 'lesson', 'session_uuid' => $session->uuid]);

        return $session->refresh();
    }

    /** The learner stops: lessons never taken are refunded, booked ones are cancelled. */
    public function quit(User $learner, LessonEnrollment $enrollment): LessonEnrollment
    {
        if ($enrollment->user_id !== $learner->id || $enrollment->status !== 'active') {
            throw new RidesException('lessons_not_editable', 409);
        }
        DB::transaction(function () use ($enrollment) {
            $enrollment = LessonEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $enrollment->sessions()->whereIn('status', ['requested', 'confirmed'])->update(['status' => 'canceled']);
            if ($enrollment->lessons_done === 0) {
                foreach (WalletTransaction::query()->whereIn('id', (array) $enrollment->wallet_transaction_ids)->get() as $tx) {
                    if (! $tx->isReversed()) {
                        $this->wallets->reverse($tx, 'lessons_canceled', ['idempotency_key' => 'lessons:'.$enrollment->uuid.':refund:'.$tx->id]);
                    }
                }
                $refund = $enrollment->amount_minor;
            } else {
                $pack = $enrollment->pack;
                $refund = 0;
                for ($n = $enrollment->lessons_done + 1; $n <= $enrollment->lessons_total; $n++) {
                    $refund += $pack->lessonShare($n);
                }
                if ($refund > 0) {
                    $this->wallets->credit($this->wallets->firstOrCreateWallet($enrollment->user()->firstOrFail(), $pack->country()->firstOrFail()), $refund, WalletBucket::SpendOnly, WalletTransactionType::Refund, [
                        'reference_type' => 'lesson_enrollment', 'reference_id' => $enrollment->id, 'idempotency_key' => 'lessons:'.$enrollment->uuid.':refund',
                        'notes' => ['key' => 'wallet.notes.lessons_refund', 'variables' => ['title' => $pack->title]],
                    ]);
                }
            }
            $enrollment->forceFill(['status' => 'canceled', 'refunded_minor' => $refund])->save();
        });
        $enrollment->refresh();
        $this->notifications->send($enrollment->driver, 'lesson.quit', 'lesson_quit_title', 'lesson_quit_body', [
            'name' => strtok((string) $learner->name, ' ') ?: '', 'title' => $enrollment->pack->title,
        ], ['type' => 'lesson', 'enrollment_uuid' => $enrollment->uuid]);

        return $enrollment;
    }

    // ---------------------------------------------------------------- rules and money

    /** @return array{0: Country, 1: RidePolicyData} */
    private function placeOf(User $learner, float $lat, float $lng): array
    {
        $cityId = $this->cities->cityAt($lat, $lng);
        $countryId = ($cityId !== null ? $this->cities->countryOf($cityId) : null) ?? $learner->country_id;
        $country = $countryId !== null ? Country::query()->find($countryId) : null;
        if ($country === null || ! $this->features->allows($country->id, $cityId, 'driving_lessons')) {
            throw RidesException::featureDisabled('driving_lessons');
        }

        return [$country, $this->policies->for($country->id, $cityId)];
    }

    private function assertTrainer(Driver $driver): void
    {
        $status = $driver->status instanceof \BackedEnum ? $driver->status->value : $driver->status;
        if ($status !== DriverStatus::Active->value) {
            throw new RidesException('hire_driver_not_active', 403);
        }
        // O-85: lessons are taught by car captains (or captains without a vehicle).
        if (! app(\Modules\Rides\Services\Drivers\VehicleKinds::class)->offers($driver, 'lessons')) {
            throw new RidesException('service_not_for_vehicle', 403);
        }
    }

    private function assertOwner(Driver $driver, int $ownerId): void
    {
        if ($ownerId !== $driver->id) {
            throw new RidesException('lessons_not_found', 404);
        }
    }

    /** @return list<int> */
    private function charge(User $learner, LessonPack $pack, LessonEnrollment $enrollment, int $amount): array
    {
        $key = 'lessons:'.$enrollment->uuid.':pay';
        $wallet = $this->wallets->firstOrCreateWallet($learner, $pack->country()->firstOrFail())->fresh();
        $free = max(0, $wallet->availableMinor(WalletBucket::SpendOnly)) + max(0, $wallet->availableMinor(WalletBucket::Withdrawable));
        if ($free < $amount) {
            throw new RidesException('insufficient_balance', 422, [], ['shortfall_minor' => $amount - $free]);
        }
        $this->wallets->debitSpendOnlyFirst($wallet, $amount, WalletTransactionType::ServicePayment, [
            'reference_type' => 'lesson_enrollment', 'reference_id' => $enrollment->id, 'idempotency_key' => $key,
            'notes' => ['key' => 'wallet.notes.lessons_payment', 'variables' => ['title' => $pack->title]],
        ]);

        return WalletTransaction::query()->whereIn('idempotency_key', [$key, $key.':spend_only', $key.':withdrawable'])->pluck('id')->all();
    }

    /** @return int the trainer's share credited */
    private function payTrainer(LessonEnrollment $enrollment, int $share, LessonSession $session): int
    {
        $pack = $enrollment->pack;
        $policy = $this->policies->for($pack->country_id, null);
        $commission = (int) round($share * $policy->int('lessons.commission_bp') / 10000);
        $country = $pack->country()->firstOrFail();
        $this->wallets->credit($this->wallets->firstOrCreateWallet($enrollment->driver()->firstOrFail(), $country), $share - $commission, WalletBucket::Withdrawable, WalletTransactionType::ServiceEarning, [
            'reference_type' => 'lesson_session', 'reference_id' => $session->id, 'idempotency_key' => 'lessons:'.$session->uuid.':earning',
            'notes' => ['key' => 'wallet.notes.lessons_earning', 'variables' => ['title' => $pack->title]],
        ]);
        if ($commission > 0 && $pack->currency !== null) {
            $this->ledger->record('trip_commissions', FinancialEntryType::Income, $commission, $pack->currency, $country, $session,
                ['key' => 'wallet.notes.lessons_commission', 'variables' => ['title' => $pack->title]]);
        }

        return $share - $commission;
    }

    private function local(Carbon $at): string
    {
        return $at->copy()->setTimezone((string) config('rides.default_timezone', 'Asia/Riyadh'))->format('Y-m-d H:i');
    }
}
