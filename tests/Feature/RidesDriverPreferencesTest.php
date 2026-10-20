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
use Modules\Driver\Models\DriverPresence;
use Modules\Driver\Models\Vehicle;
use Modules\Driver\Models\VehicleColor;
use Modules\Driver\Models\VehicleMake;
use Modules\Driver\Models\VehicleModel;
use Modules\Rides\Enums\OfferStatus;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Events\DriverChanged;
use Modules\Rides\Events\RideChanged;
use Modules\Rides\Events\RideDriverMoved;
use Modules\Rides\Models\DriverPreference;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Services\Dispatch\DispatchEngine;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Batch 6 of docs/rides_tasks.md: the captain's driving preferences (D-32..D-37) — the categories they
 * want, how far they go for a pickup, and Auto-Accept with its price and payment filters, which exist
 * only where the switch is on and the policy allows them.
 */
class RidesDriverPreferencesTest extends TestCase
{
    private const FROM = ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'];

    private const TO = ['lat' => 24.7500, 'lng' => 46.7000, 'address' => 'Malaz'];

    private Country $saudi;

    private User $rider;

    private int $economyId;

    private int $plate = 2000;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response([], 200));
        Event::fake([RideChanged::class, RideDriverMoved::class, DriverChanged::class]);
        config(['rides.regulator_enforced' => false, 'broadcasting.default' => 'null']);

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id,
        ]);
        DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 24.7136, 'lng' => 46.6753, 'status' => true, 'sort_order' => 1]);
        $this->economyId = RideCategory::query()->create([
            'slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'capacity_max' => 4, 'flags' => ['cash_allowed' => true],
        ])->id;
        $comfort = new RideCategory(['slug' => 'comfort', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 2, 'capacity_max' => 4, 'flags' => ['cash_allowed' => true]]);
        $comfort->fillAllTranslations(['name' => ['ar' => 'مريح', 'en' => 'Comfort']])->save();
        RideCategory::query()->whereKey($this->economyId)->first()->fillAllTranslations(['name' => ['ar' => 'اقتصادي', 'en' => 'Economy']])->save();
        $this->switches(['rides', 'category.economy', 'category.comfort', 'cash', 'wallet']);
        app(RidePolicies::class)->save($this->saudi->id, null, null, null, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
        ]);
        RidePolicies::forget();

        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_the_categories_are_chosen_kept_and_never_empty(): void
    {
        $this->getJson('/api/driver/v1/preferences')->assertUnauthorized();
        $captain = $this->captain('Omar', '+966550000001', 24.7200, 46.6753);

        $show = $this->asDriver($captain)->getJson('/api/driver/v1/preferences')->assertOk();
        $this->assertSame(['economy', 'comfort'], array_column($show->json('data.categories'), 'slug'));
        $this->assertSame(['Economy', 'Comfort'], array_column($show->json('data.categories'), 'name'));
        $this->assertSame([true, true], array_column($show->json('data.categories'), 'selected'));
        $show->assertJsonPath('data.pickup_radius_m', null)->assertJsonPath('data.auto_accept.available', false)->assertJsonPath('data.auto_accept.enabled', false);

        // Only comfort: economy is off for them (D-32) — and a ride in economy no longer finds them.
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['categories' => ['comfort']])->assertOk()
            ->assertJsonPath('data.categories.0.selected', false)->assertJsonPath('data.categories.1.selected', true);
        $this->assertSame(['comfort'], DriverPreference::query()->find($captain->id)->categories);
        $this->book();
        $this->asDriver($captain)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(0, 'data');

        // Everything picked = no narrowing, stored as "all"; sending null does the same.
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['categories' => ['economy', 'comfort']])->assertOk();
        $this->assertNull(DriverPreference::query()->find($captain->id)->categories);
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['categories' => ['comfort']])->assertOk();
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['categories' => null])->assertOk()->assertJsonPath('data.categories.0.selected', true);

        // None, or one they can't take, is refused.
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['categories' => []])->assertStatus(422)->assertJsonPath('error_code', 'rides_preferences_no_category');
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['categories' => ['spaceship']])->assertStatus(422)->assertJsonPath('error_code', 'rides_preferences_category_unavailable');
    }

    public function test_the_pickup_range_keeps_far_requests_away_until_it_is_widened(): void
    {
        $far = $this->captain('Far', '+966550000001', 24.7536, 46.6753); // ≈ 4.5 km from the pickup

        $this->asDriver($far)->putJson('/api/driver/v1/preferences', ['pickup_radius_m' => 3000])->assertOk()->assertJsonPath('data.pickup_radius_m', 3000)->assertJsonPath('data.pickup_radius_max_m', 50000);
        $ride = $this->book();
        $this->asDriver($far)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(0, 'data');

        // Too far to promise, then a range that reaches: the next round finds them.
        $this->asDriver($far)->putJson('/api/driver/v1/preferences', ['pickup_radius_m' => 80000])->assertStatus(422)->assertJsonPath('error_code', 'rides_preferences_radius_too_far');
        $this->asDriver($far)->putJson('/api/driver/v1/preferences', ['pickup_radius_m' => 6000])->assertOk();
        app(DispatchEngine::class)->runRound($ride->refresh());
        $this->asDriver($far)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(1, 'data');

        $this->asDriver($far)->putJson('/api/driver/v1/preferences', ['pickup_radius_m' => null])->assertOk()->assertJsonPath('data.pickup_radius_m', null);
    }

    public function test_auto_accept_needs_its_switch_and_takes_a_request_that_fits(): void
    {
        $near = $this->captain('Near', '+966550000001', 24.7200, 46.6753); // ≈ 0.7 km
        $far = $this->captain('Far', '+966550000002', 24.7536, 46.6753);    // ≈ 4.5 km

        // No switch: not offered, and not turned on.
        $this->asDriver($near)->putJson('/api/driver/v1/preferences', ['auto_accept' => ['enabled' => true]])->assertStatus(403)->assertJsonPath('error_code', 'rides_feature_disabled');

        $this->switches(['auto_accept']);
        $this->asDriver($near)->getJson('/api/driver/v1/preferences')->assertJsonPath('data.auto_accept.available', true)->assertJsonPath('data.auto_accept.price_filter.allowed', false);
        $this->asDriver($near)->putJson('/api/driver/v1/preferences', ['auto_accept' => ['enabled' => true, 'max_pickup_m' => 2000]])->assertOk()
            ->assertJsonPath('data.auto_accept.enabled', true)->assertJsonPath('data.auto_accept.max_pickup_m', 2000);
        $this->asDriver($far)->putJson('/api/driver/v1/preferences', ['auto_accept' => ['enabled' => true, 'max_pickup_m' => 2000]])->assertOk();

        // The nearest captain is asked first: it fits their rule, so the ride is theirs at once.
        $ride = $this->book();
        $ride->refresh();
        $this->assertSame([RideStatus::Accepted, $near->id], [$ride->status, $ride->driver_id]);
        $this->assertSame(OfferStatus::Accepted, RideOffer::query()->where('ride_id', $ride->id)->where('driver_id', $near->id)->value('status'));
        $this->asDriver($near)->getJson('/api/driver/v1/rides/current')->assertOk()->assertJsonPath('data.uuid', $ride->uuid);
        $this->assertSame(1, (int) $this->asDriver($near)->getJson('/api/driver/v1/notifications/unread-count')->json('data.count'));

        // A captain whose rule the pickup is too far for is rung like anyone else.
        $this->cancelAll();
        DriverPresence::query()->where('driver_id', $near->id)->update(['status' => PresenceStatus::Offline->value]);
        $second = $this->book();
        $this->assertSame(RideStatus::Searching, $second->refresh()->status);
        $this->asDriver($far)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_auto_accept_filters_exist_only_where_the_policy_allows_them(): void
    {
        $this->switches(['auto_accept']);
        $captain = $this->captain('Omar', '+966550000001', 24.7200, 46.6753);

        // Not allowed here: refused, and not shown.
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['auto_accept' => ['min_fare_minor' => 100]])->assertStatus(422)->assertJsonPath('error_code', 'rides_auto_accept_filter_not_allowed');
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['auto_accept' => ['payment_methods' => ['cash']]])->assertStatus(422)->assertJsonPath('error_code', 'rides_auto_accept_filter_not_allowed');

        app(RidePolicies::class)->save($this->saudi->id, null, null, null, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
            'auto_accept.price_filter' => true, 'auto_accept.payment_filter' => true,
        ]);
        RidePolicies::forget();
        $this->asDriver($captain)->getJson('/api/driver/v1/preferences')->assertJsonPath('data.auto_accept.price_filter.allowed', true)->assertJsonPath('data.auto_accept.payment_filter.allowed', true)
            ->assertJsonPath('data.auto_accept.payment_filter.options', ['wallet', 'card', 'cash']);

        // A minimum no ride of this size reaches: it rings instead of being taken (D-36).
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['auto_accept' => ['enabled' => true, 'min_fare_minor' => 1_000_000]])->assertOk();
        $rung = $this->book();
        $this->assertSame(RideStatus::Searching, $rung->refresh()->status);
        $this->asDriver($captain)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(1, 'data');
        $this->cancelRide($rung);

        // A per-km minimum no ride reaches, too.
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['auto_accept' => ['min_fare_minor' => null, 'min_per_km_minor' => 1_000_000]])->assertOk();
        $this->assertSame(RideStatus::Searching, $this->book()->refresh()->status);
        $this->cancelAll();

        // The payment filter (D-37): wallet only → a cash ride rings; cash included → it is taken.
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['auto_accept' => ['min_per_km_minor' => null, 'payment_methods' => ['wallet']]])->assertOk();
        $this->assertSame(RideStatus::Searching, $this->book('cash')->refresh()->status);
        $this->cancelAll();
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['auto_accept' => ['payment_methods' => ['wallet', 'cash']]])->assertOk();
        $this->assertSame(RideStatus::Accepted, $this->book('cash')->refresh()->status);
    }

    public function test_switching_auto_accept_off_stops_it_everywhere(): void
    {
        $this->switches(['auto_accept']);
        $captain = $this->captain('Omar', '+966550000001', 24.7200, 46.6753);
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['auto_accept' => ['enabled' => true]])->assertOk();

        // The country turns the feature off afterwards: the captain's own setting stays, but nothing is taken for them.
        $this->switches(['auto_accept'], false);
        $this->asDriver($captain)->getJson('/api/driver/v1/preferences')->assertJsonPath('data.auto_accept.available', false)->assertJsonPath('data.auto_accept.enabled', false);
        $this->assertSame(RideStatus::Searching, $this->book()->refresh()->status);
        $this->assertTrue((bool) DriverPreference::query()->find($captain->id)->auto_accept['enabled']);

        // Turning it off themselves is always allowed.
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['auto_accept' => ['enabled' => false]])->assertOk();
        $this->assertFalse((bool) DriverPreference::query()->find($captain->id)->auto_accept['enabled']);
    }

    private function book(string $payment = 'cash'): Ride
    {
        $quote = $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::FROM, 'destination' => self::TO])->assertOk()->json('data.0');
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides', ['quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => $payment], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return Ride::query()->latest('id')->firstOrFail();
    }

    private function cancelRide(Ride $ride): void
    {
        $ride->refresh();
        RideOffer::query()->where('ride_id', $ride->id)->update(['status' => OfferStatus::Withdrawn->value]);
        $ride->forceFill(['status' => RideStatus::Canceled])->save();
        RideActiveLock::query()->where('ride_id', $ride->id)->delete();
    }

    private function cancelAll(): void
    {
        Ride::query()->whereIn('status', [RideStatus::Searching->value, RideStatus::Accepted->value])->get()->each(fn (Ride $r) => $this->cancelRide($r));
    }

    private function switches(array $features, bool $enabled = true): void
    {
        foreach ($features as $feature) {
            RideFeatureRule::query()->updateOrCreate(['feature' => $feature, 'country_id' => $this->saudi->id], ['enabled' => $enabled]);
        }
        RideFeatures::forget();
        RidePolicies::forget();
        CityResolver::forget();
    }

    private function captain(string $name, string $phone, float $lat, float $lng): Driver
    {
        $driver = Driver::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => $name, 'phone' => $phone, 'phone_verified_at' => now(),
            'country_id' => $this->saudi->id, 'status' => DriverStatus::Active,
        ]);
        $make = VehicleMake::query()->create(['status' => true]);
        $model = VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => 'sedan', 'seats' => 4, 'status' => true]);
        Vehicle::query()->create([
            'uuid' => (string) Str::uuid(), 'driver_id' => $driver->id, 'country_id' => $this->saudi->id, 'make_id' => $make->id, 'model_id' => $model->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#FFFFFF', 'status' => true])->id,
            'year' => 2022, 'body_type' => 'sedan', 'seats' => 4, 'plate_letters' => 'ا ب ج', 'plate_number' => (string) $this->plate++, 'plate_type' => 1,
            'status' => VehicleStatus::Approved, 'is_active' => true,
        ]);
        DriverPresence::query()->create([
            'driver_id' => $driver->id, 'status' => PresenceStatus::Online, 'lat' => $lat, 'lng' => $lng, 'gps_enabled' => true,
            'online_since' => now(), 'last_seen_at' => now(),
        ]);

        return $driver;
    }

    private function asDriver(Driver $driver): static
    {
        return $this->actingAs($driver, 'driver_api');
    }
}
