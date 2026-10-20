<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Discover\Models\DiscoverCity;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Enums\VehicleStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverLocationBatch;
use Modules\Driver\Models\DriverLocationLog;
use Modules\Driver\Models\DriverPresence;
use Modules\Driver\Models\Vehicle;
use Modules\Driver\Models\VehicleColor;
use Modules\Driver\Models\VehicleMake;
use Modules\Driver\Models\VehicleModel;
use Modules\Rides\Events\RideChanged;
use Modules\Rides\Events\RideDriverMoved;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideEvent;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Models\RideSafetyIncident;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * The Safety Center (R-112, R-113, R-117, R-219..230, D-124, D-140..159, D-187, D-396, D-410):
 * trusted contacts get the live link by themselves and "arrived safely", the ride code by the
 * rider's choice, blocking and favourite captains in matching, "this isn't the car" cancelling free,
 * reports, and "Are you OK?" after a long stop or after arriving — unanswered, it reaches the
 * safety team and the trusted contacts.
 */
class RidesSafetyCenterTest extends TestCase
{
    private const PICKUP = ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'];

    private const DROPOFF = ['lat' => 24.7500, 'lng' => 46.7000, 'address' => 'King Fahd Rd'];

    private Country $saudi;

    private User $rider;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Event::fake([RideChanged::class, RideDriverMoved::class]);
        config(['rides.regulator_enforced' => false, 'broadcasting.default' => 'null']);
        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2])->id,
        ]);
        DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 24.7136, 'lng' => 46.6753, 'status' => true, 'sort_order' => 1]);
        $economy = RideCategory::query()->create(['slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'flags' => ['cash_allowed' => true]]);
        foreach (['rides', 'category.economy', 'cash', 'share'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        RideFeatures::forget();
        RidePolicies::forget();
        CityResolver::forget();
        app(RidePolicies::class)->save($this->saudi->id, null, null, $economy->id, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
            'price.cancel_fee_minor' => 1000,
        ]);
        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_trusted_contacts_and_preferences_are_kept_per_person(): void
    {
        $this->asRider()->postJson('/api/mobile/v1/rides/safety/contacts', ['name' => 'Mona', 'phone' => '+966500000077', 'share_mode' => 'all'])->assertCreated()->assertJsonCount(1, 'data');
        $this->asRider()->postJson('/api/mobile/v1/rides/safety/contacts', ['name' => 'Me', 'phone' => '+966500000001'])->assertStatus(422)->assertJsonPath('error_code', 'rides_contact_is_you');
        for ($i = 2; $i <= 5; $i++) {
            $this->asRider()->postJson('/api/mobile/v1/rides/safety/contacts', ['name' => "C$i", 'phone' => '+96650000010'.$i])->assertCreated();
        }
        $this->asRider()->postJson('/api/mobile/v1/rides/safety/contacts', ['name' => 'C6', 'phone' => '+966500000106'])->assertStatus(422)->assertJsonPath('error_code', 'rides_contacts_limit');

        $this->asRider()->putJson('/api/mobile/v1/rides/safety/preferences', ['pin_mode' => 'always', 'checkin_after_min' => 15, 'seatbelt_reminder' => false])->assertOk()
            ->assertJsonPath('data.pin_mode', 'always')->assertJsonPath('data.checkin_after_min', 15);
        $center = $this->asRider()->getJson('/api/mobile/v1/rides/safety')->assertOk()->json('data');
        $this->assertCount(5, $center['contacts']);
        $this->assertSame('911', $center['emergency_number']);

        // A captain's list is their own.
        $this->asDriver($this->driver())->getJson('/api/driver/v1/safety')->assertOk()->assertJsonCount(0, 'data.contacts');
    }

    public function test_every_ride_contacts_get_the_link_on_accept_and_arrived_safely_at_the_end_and_the_pin_follows_the_choice(): void
    {
        $this->asRider()->postJson('/api/mobile/v1/rides/safety/contacts', ['name' => 'Mona', 'phone' => '+966500000077', 'share_mode' => 'all'])->assertCreated();
        $this->asRider()->postJson('/api/mobile/v1/rides/safety/contacts', ['name' => 'Night', 'phone' => '+966500000078', 'share_mode' => 'night'])->assertCreated();
        $this->asRider()->putJson('/api/mobile/v1/rides/safety/preferences', ['pin_mode' => 'always'])->assertOk();
        $this->travelTo(now('Asia/Riyadh')->setTime(12, 0)->utc());

        $driver = $this->driver();
        $uuid = $this->bookAndAccept($driver);
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertNotNull($ride->pin, 'the rider asked for the ride code on every ride');

        $shared = RideEvent::query()->where('ride_id', $ride->id)->where('type', 'contacts_notified')->sole();
        $this->assertSame('share', $shared->payload['kind']);
        $this->assertCount(1, $shared->payload['contact_ids'], 'the night-only contact is not told at noon');

        $this->drive($driver, $uuid, $ride->pin);
        $arrived = RideEvent::query()->where('ride_id', $ride->id)->where('type', 'contacts_notified')->get()->firstWhere(fn ($e) => $e->payload['kind'] === 'arrived');
        $this->assertNotNull($arrived);
        $this->assertSame($shared->payload['contact_ids'], $arrived->payload['contact_ids']);
    }

    public function test_a_blocked_captain_is_never_offered_again(): void
    {
        $near = $this->driver(24.7140, 46.6755);
        $far = $this->driver(24.7300, 46.6900);
        $uuid = $this->bookAndAccept($near);
        $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/block", ['reason' => 'rude'])->assertOk();
        $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/cancel")->assertOk();

        $this->book();
        $this->assertSame($far->id, RideOffer::query()->where('status', 'sent')->sole()->driver_id, 'the blocked captain is skipped');
        $this->asRider()->getJson('/api/mobile/v1/rides/safety')->assertOk()->assertJsonCount(1, 'data.blocked');
    }

    public function test_a_favourite_captain_is_asked_first_even_if_farther(): void
    {
        // One captain per round (O-36's several-at-once is tested on its own).
        config(['rides.policy_defaults' => ['search.batch_size' => 1] + config('rides.policy_defaults')]);
        $near = $this->driver(24.7140, 46.6755);
        $far = $this->driver(24.7300, 46.6900);
        $uuid = $this->bookAndAccept($far);
        $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/favorite", ['on' => true])->assertOk();
        $this->asRider()->getJson("/api/mobile/v1/rides/$uuid")->assertOk()->assertJsonPath('data.favorite_driver', true);
        $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/cancel")->assertOk();

        $this->book();
        $this->assertSame($far->id, RideOffer::query()->where('status', 'sent')->sole()->driver_id);
        $this->assertNotSame($near->id, $far->id);
    }

    public function test_not_the_booked_car_cancels_free_and_reports_it(): void
    {
        $driver = $this->driver();
        $uuid = $this->bookAndAccept($driver);
        $this->travel(5)->minutes(); // past the free window: a normal cancel would cost the fee

        $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/vehicle-mismatch", ['note' => 'Different plate'])->assertOk()->assertJsonPath('data.status', 'canceled');
        $this->assertSame('vehicle_mismatch', RideSafetyIncident::query()->sole()->type);
        $this->assertFalse(\Modules\Rides\Models\RideAdjustment::query()->where('type', 'cancel_fee')->exists(), 'no fee');
    }

    public function test_captain_reports_and_the_accident_guidance(): void
    {
        $driver = $this->driver();
        $uuid = $this->bookAndAccept($driver);

        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/report", ['type' => 'accident', 'note' => 'Minor crash'])->assertCreated()
            ->assertJsonPath('data.emergency_number', '911')->assertJsonCount(7, 'data.guidance');
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/report", ['type' => 'unsafe_driving'])->assertStatus(422);
        $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/report", ['type' => 'unsafe_driving'])->assertCreated();
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/block", [])->assertOk();
        $this->asDriver($driver)->getJson('/api/driver/v1/safety')->assertOk()->assertJsonPath('data.blocked.0.name', 'Sara');
        $this->assertSame(['accident', 'unsafe_driving'], RideSafetyIncident::query()->orderBy('id')->pluck('type')->all());
    }

    public function test_a_long_stop_asks_both_and_silence_reaches_the_safety_team_and_the_contacts(): void
    {
        $driver = $this->driver();
        $this->asDriver($driver)->postJson('/api/driver/v1/safety/contacts', ['name' => 'Brother', 'phone' => '+966500000099'])->assertCreated();
        $uuid = $this->bookAndAccept($driver);
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/arrive", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk();
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/start", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk();
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();

        // Six minutes standing still half-way, far from the destination.
        $this->travel(6)->minutes();
        $batch = DriverLocationBatch::query()->create(['driver_id' => $driver->id, 'batch_uuid' => (string) Str::uuid(), 'points_count' => 5, 'received_at' => now()]);
        foreach ([290, 220, 150, 80, 10] as $ago) {
            DriverLocationLog::query()->create(['driver_id' => $driver->id, 'ride_id' => $ride->id, 'batch_id' => $batch->id, 'lat' => 24.7300, 'lng' => 46.6880, 'recorded_at' => now()->subSeconds($ago)]);
        }
        $this->artisan('rides:dispatch-tick')->assertSuccessful();

        $check = $this->asRider()->getJson("/api/mobile/v1/rides/$uuid")->assertOk()->json('data.safety_check');
        $this->assertSame('long_stop', $check['reason']);
        $this->asDriver($driver)->getJson('/api/driver/v1/rides/current')->assertOk()->assertJsonPath('data.safety_check.reason', 'long_stop');

        $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/safety-checks/{$check['uuid']}", ['answer' => 'ok'])->assertOk();
        $this->asRider()->getJson("/api/mobile/v1/rides/$uuid")->assertOk()->assertJsonPath('data.safety_check', null);

        // The captain doesn't answer.
        $this->travel(121)->seconds();
        $this->artisan('rides:dispatch-tick')->assertSuccessful();
        $incident = RideSafetyIncident::query()->sole();
        $this->assertSame(['ride_check', 'driver'], [$incident->type, $incident->reporter_type]);
        $alert = RideEvent::query()->where('ride_id', $ride->id)->where('type', 'contacts_notified')->get()->firstWhere(fn ($e) => $e->payload['kind'] === 'alert');
        $this->assertNotNull($alert, "the captain's trusted contact is alerted");

        // Not asked again of the same kind straight away.
        $this->artisan('rides:dispatch-tick')->assertSuccessful();
        $this->assertSame(2, \Modules\Rides\Models\RideSafetyCheck::query()->count());
    }

    public function test_the_check_in_after_arriving_and_its_help_answer(): void
    {
        $this->asRider()->putJson('/api/mobile/v1/rides/safety/preferences', ['checkin_after_min' => 10])->assertOk();
        $driver = $this->driver();
        $uuid = $this->bookAndAccept($driver);
        $this->drive($driver, $uuid, null);

        $this->travel(10)->minutes();
        $this->artisan('rides:dispatch-tick')->assertSuccessful();
        $check = $this->asRider()->getJson("/api/mobile/v1/rides/$uuid")->assertOk()->json('data.safety_check');
        $this->assertSame('checkin', $check['kind']);

        $this->asRider()->postJson("/api/mobile/v1/rides/$uuid/safety-checks/{$check['uuid']}", ['answer' => 'help', 'lat' => 24.75, 'lng' => 46.70])->assertOk()
            ->assertJsonPath('data.emergency_number', '911');
        $this->assertSame('sos', RideSafetyIncident::query()->sole()->type);
    }

    // ---------------------------------------------------------------- helpers

    private function book(): string
    {
        $quote = $this->asRider()->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::PICKUP, 'destination' => self::DROPOFF])->json('data.0');

        return $this->postJson('/api/mobile/v1/rides', ['quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => 'cash'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->json('data.uuid');
    }

    private function bookAndAccept(Driver $driver): string
    {
        $uuid = $this->book();
        RideOffer::query()->where('status', 'sent')->where('driver_id', '!=', $driver->id)->update(['status' => 'expired']);
        $offer = RideOffer::query()->where('driver_id', $driver->id)->where('status', 'sent')->first()
            ?? RideOffer::query()->create([
                'ride_id' => Ride::query()->where('uuid', $uuid)->value('id'), 'driver_id' => $driver->id, 'round' => 9, 'distance_m' => 100, 'eta_s' => 60,
                'status' => 'sent', 'sent_at' => now(), 'expires_at' => now()->addSeconds(15), 'score' => 1,
            ]);
        $this->asDriver($driver)->postJson("/api/driver/v1/offers/{$offer->uuid}/accept")->assertOk();

        return $uuid;
    }

    private function drive(Driver $driver, string $uuid, ?string $pin): void
    {
        $at = ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']];
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/arrive", $at)->assertOk();
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/start", $at + ($pin ? ['pin' => $pin] : []))->assertOk();
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/end", ['lat' => self::DROPOFF['lat'], 'lng' => self::DROPOFF['lng']])->assertOk()->assertJsonPath('data.status', 'completed');
    }

    private function driver(float $lat = 24.7150, float $lng = 46.6760): Driver
    {
        $n = ++$this->n;
        $driver = Driver::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => "Captain $n", 'phone' => '+96655000000'.$n, 'phone_verified_at' => now(),
            'country_id' => $this->saudi->id, 'status' => DriverStatus::Active,
        ]);
        $make = VehicleMake::query()->create(['status' => true]);
        $model = VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => 'sedan', 'seats' => 4, 'status' => true]);
        Vehicle::query()->create([
            'uuid' => (string) Str::uuid(), 'driver_id' => $driver->id, 'country_id' => $this->saudi->id, 'make_id' => $make->id, 'model_id' => $model->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#FFFFFF', 'status' => true])->id,
            'year' => 2022, 'body_type' => 'sedan', 'seats' => 4, 'plate_letters' => 'ا ب ج', 'plate_number' => (string) (1000 + $n), 'plate_type' => 1,
            'status' => VehicleStatus::Approved, 'is_active' => true,
        ]);
        DriverPresence::query()->create([
            'driver_id' => $driver->id, 'status' => PresenceStatus::Online, 'lat' => $lat, 'lng' => $lng, 'gps_enabled' => true,
            'online_since' => now(), 'last_seen_at' => now(),
        ]);

        return $driver;
    }

    private function asRider(): static
    {
        return $this->actingAs($this->rider, 'user_api');
    }

    private function asDriver(Driver $driver): static
    {
        return $this->actingAs($driver, 'driver_api');
    }
}
