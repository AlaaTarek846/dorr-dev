<?php

namespace Tests\Feature;

use App\Enums\Gender;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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
use Modules\Rides\Events\RideChanged;
use Modules\Rides\Events\RideDriverMoved;
use Modules\Rides\Models\FavoriteDriver;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Notifications\ArrivingSoon;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * The owner's fifth round of test notes (docs/rides_tasks.md O-72..O-83).
 */
class RidesOwnerNotesFiveTest extends TestCase
{
    private const PICKUP = ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'];

    private const DROPOFF = ['lat' => 24.7500, 'lng' => 46.7000, 'address' => 'King Fahd Rd'];

    private Country $saudi;

    private User $rider;

    private int $economyId;

    private int $plates = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response([], 200));
        Event::fake([RideChanged::class, RideDriverMoved::class]);
        config(['rides.regulator_enforced' => false, 'broadcasting.default' => 'null']);

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id,
        ]);
        DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 24.7136, 'lng' => 46.6753, 'status' => true, 'sort_order' => 1]);
        $this->economyId = RideCategory::query()->create([
            'slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'capacity_max' => 4,
            'flags' => ['cash_allowed' => true],
        ])->id;
        $ladies = RideCategory::query()->create([
            'slug' => 'ladies', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 2, 'capacity_max' => 4,
            'flags' => ['cash_allowed' => true], 'eligibility' => ['driver_gender' => 'female', 'rider_gender' => 'female'],
        ])->id;
        foreach (['rides', 'category.economy', 'category.ladies', 'wallet', 'cash', 'favorite_circle'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        RideFeatures::forget();
        CityResolver::forget();
        foreach ([$this->economyId, $ladies] as $category) {
            app(RidePolicies::class)->save($this->saudi->id, null, null, $category, [
                'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
            ]);
        }
        RidePolicies::forget();

        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now(), 'gender' => Gender::Male]);
    }

    public function test_for_her_shows_only_to_women_and_goes_to_women_captains(): void
    {
        // O-73: a man never sees For Her; a man captain isn't offered it in his preferences.
        $this->assertSame(['economy'], collect($this->quote()->assertOk()->json('data'))->pluck('category.slug')->all());
        $man = $this->onlineDriver(Gender::Male);
        $woman = $this->onlineDriver(Gender::Female);
        $this->assertNotContains('ladies', collect($this->asDriver($man)->getJson('/api/driver/v1/preferences')->assertOk()->json('data.categories'))->pluck('slug')->all());
        $this->assertContains('ladies', collect($this->asDriver($woman)->getJson('/api/driver/v1/preferences')->assertOk()->json('data.categories'))->pluck('slug')->all());

        // O-74: a woman rider sees it, and the request goes to the woman captain only.
        $this->rider->forceFill(['gender' => Gender::Female])->save();
        $quote = collect($this->quote()->assertOk()->json('data'))->firstWhere('category.slug', 'ladies');
        $this->assertNotNull($quote);
        $this->fund($this->rider, 50000);
        $this->book($quote, 'wallet')->assertCreated();
        $this->assertSame([$woman->id], RideOffer::query()->pluck('driver_id')->all());
    }

    public function test_a_request_made_before_the_captain_goes_online_reaches_them_at_once(): void
    {
        $this->fund($this->rider, 50000);
        $uuid = $this->book($this->quote()->json('data.0'), 'wallet')->assertCreated()->json('data.uuid');
        $this->assertSame(0, RideOffer::query()->count());

        // O-79: the captain starts work after the request — it reaches them on going online, no scheduler needed.
        $captain = $this->onlineDriver(Gender::Male, online: false);
        $this->asDriver($captain)->postJson('/api/driver/v1/presence/online', ['lat' => 24.7150, 'lng' => 46.6760])->assertOk();
        $this->assertSame($captain->id, RideOffer::query()->where('ride_id', Ride::query()->where('uuid', $uuid)->value('id'))->value('driver_id'));
    }

    public function test_the_arrival_range_follows_the_dashboard_and_the_rider_hears_the_captain_is_close(): void
    {
        $captain = $this->onlineDriver(Gender::Male);
        $this->fund($this->rider, 50000);
        $uuid = $this->book($this->quote()->json('data.0'), 'wallet')->assertCreated()->json('data.uuid');
        $offer = RideOffer::query()->where('driver_id', $captain->id)->firstOrFail();
        $this->asDriver($captain)->postJson("/api/driver/v1/offers/{$offer->uuid}/accept")->assertOk();
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();

        // O-72: far away nothing; about 1 km out (≈2 min) the rider is told — once.
        $soon = app(ArrivingSoon::class);
        $soon->onLocation($ride->id, 24.7600, 46.6753);
        $soon->onLocation($ride->id, 24.7226, 46.6753);
        $soon->onLocation($ride->id, 24.7200, 46.6753);
        $this->assertSame(1, $this->rider->notifications()->get()->filter(fn ($n) => ($n->data['event'] ?? null) === 'ride.arriving_soon')->count());

        // O-76, O-77: 200 m away «arrived» is refused with the distance and the range; the dashboard widens it — at once.
        $away = ['lat' => 24.7154, 'lng' => 46.6753];
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$uuid/arrive", $away)
            ->assertStatus(422)->assertJsonPath('error_code', 'rides_not_at_pickup')->assertJsonPath('data.radius_m', 100);
        app(RidePolicies::class)->save($this->saudi->id, null, null, null, ['arrival.radius_m' => 300]);
        RidePolicies::forget();
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$uuid/arrive", $away)->assertOk()->assertJsonPath('data.status', 'arrived');
    }

    public function test_favourite_captains_show_who_is_free_and_how_far_nearest_first(): void
    {
        $far = $this->onlineDriver(Gender::Male);
        DriverPresence::query()->where('driver_id', $far->id)->update(['lat' => 24.7400, 'lng' => 46.6753]);
        $near = $this->onlineDriver(Gender::Male);
        $off = $this->onlineDriver(Gender::Male, online: false);
        foreach ([$off, $far, $near] as $d) {
            FavoriteDriver::query()->create(['user_id' => $this->rider->id, 'driver_id' => $d->id, 'created_at' => now()]);
        }

        // O-81: free ones first, nearest first, with the distance; the one offline last, without it.
        $list = $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/circle?lat=24.7136&lng=46.6753')->assertOk()->json('data.drivers');
        $this->assertSame([$near->id, $far->id, $off->id], array_column($list, 'driver_id'));
        $this->assertSame([true, true, false], array_column($list, 'available'));
        $this->assertEqualsWithDelta(0.2, $list[0]['distance_km'], 0.11);
        $this->assertNull($list[2]['distance_km']);
    }

    public function test_each_vehicle_gets_only_its_own_work(): void
    {
        $moto = RideCategory::query()->create([
            'slug' => 'motorcycle', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 3, 'capacity_max' => 1,
            'flags' => ['cash_allowed' => true], 'eligibility' => ['body_types' => ['motorcycle']],
        ]);
        RideFeatureRule::query()->create(['feature' => 'category.motorcycle', 'country_id' => $this->saudi->id, 'enabled' => true]);
        RideFeatures::forget();
        app(RidePolicies::class)->save($this->saudi->id, null, null, $moto->id, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.min_fare_minor' => 1500, 'hourly.enabled' => true, 'hourly.hour_price_minor' => 3000,
        ]);
        RidePolicies::forget();

        // O-85: a bus never gets an Economy request — only the car does; each app shows only its own services.
        $bus = $this->onlineDriver(Gender::Male, body: 'bus');
        $car = $this->onlineDriver(Gender::Male);
        $this->fund($this->rider, 50000);
        $this->book(collect($this->quote()->json('data'))->firstWhere('category.slug', 'economy'), 'wallet')->assertCreated();
        $this->assertSame([$car->id], RideOffer::query()->pluck('driver_id')->all());
        $this->asDriver($bus)->getJson('/api/driver/v1/work-services')->assertOk()->assertJsonPath('data.services', ['bus']);
        $this->asDriver($car)->getJson('/api/driver/v1/work-services')->assertOk()->assertJsonPath('data.services', ['rides', 'with_me', 'scheduled', 'airport', 'hire', 'lessons']);
        // …and a car captain isn't even offered Motorcycle in his preferences.
        $this->assertNotContains('motorcycle', collect($this->asDriver($car)->getJson('/api/driver/v1/preferences')->assertOk()->json('data.categories'))->pluck('slug')->all());

        // O-86: the motorcycle is by the hour only, with no destination needed (open rides being off here).
        $moto->forceFill(['flags' => ['cash_allowed' => true, 'hourly_only' => true]])->save();
        $open = $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::PICKUP])->assertOk()->json('data');
        $this->assertSame(['motorcycle'], array_column(array_column($open, 'category'), 'slug'));
        $this->assertTrue($open[0]['hourly_offer']['only']);
        $this->assertSame(1, $open[0]['hourly']['hours']);
        $this->assertSame(3000, $open[0]['total_minor']);
    }

    public function test_the_find_a_driver_price_follows_what_the_country_offers(): void
    {
        RideFeatureRule::query()->create(['feature' => 'find_driver', 'country_id' => $this->saudi->id, 'enabled' => true]);
        RideFeatures::forget();
        app(RidePolicies::class)->save($this->saudi->id, null, null, null, ['hire.hour_price_minor' => 0, 'hire.day_price_minor' => 20000, 'hire.max_days' => 3]);
        RidePolicies::forget();

        // O-84: asked by the hour with no hour price, and too many days: priced by the day, within the range — never a dead end.
        $as = $this->actingAs($this->rider, 'user_api');
        $as->getJson('/api/mobile/v1/rides/hire/quote?lat=24.7136&lng=46.6753&unit=hour&quantity=2')->assertOk()
            ->assertJsonPath('data.unit', 'day')->assertJsonPath('data.quantity', 2)->assertJsonPath('data.units', ['day'])->assertJsonPath('data.amount_minor', 40000);
        $as->getJson('/api/mobile/v1/rides/hire/quote?lat=24.7136&lng=46.6753&unit=day&quantity=9')->assertOk()->assertJsonPath('data.quantity', 3);

        // A bus captain doesn't take "drive my car" jobs (O-85).
        $this->asDriver($this->onlineDriver(Gender::Male, body: 'bus'))->getJson('/api/driver/v1/hire/open')
            ->assertStatus(403)->assertJsonPath('error_code', 'rides_service_not_for_vehicle');
    }

    /** @param array<string, mixed> $extra */
    private function quote(array $extra = []): TestResponse
    {
        return $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::PICKUP, 'destination' => self::DROPOFF] + $extra);
    }

    /** @param array<string, mixed> $quote */
    private function book(array $quote, string $method): TestResponse
    {
        return $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides', ['quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => $method], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function onlineDriver(Gender $gender, bool $online = true, string $body = 'sedan'): Driver
    {
        $n = ++$this->plates;
        $driver = Driver::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => "Captain $n", 'phone' => '+96655000000'.$n, 'phone_verified_at' => now(),
            'country_id' => $this->saudi->id, 'status' => DriverStatus::Active, 'gender' => $gender,
        ]);
        $make = VehicleMake::query()->create(['status' => true]);
        $model = VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => $body, 'seats' => 4, 'status' => true]);
        Vehicle::query()->create([
            'uuid' => (string) Str::uuid(), 'driver_id' => $driver->id, 'country_id' => $this->saudi->id, 'make_id' => $make->id, 'model_id' => $model->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#FFFFFF', 'status' => true])->id,
            'year' => 2022, 'body_type' => $body, 'seats' => 4, 'plate_letters' => 'ا ب ج', 'plate_number' => (string) (1000 + $n), 'plate_type' => 1,
            'status' => VehicleStatus::Approved, 'is_active' => true,
        ]);
        DriverPresence::query()->create([
            'driver_id' => $driver->id, 'status' => $online ? PresenceStatus::Online : PresenceStatus::Offline, 'lat' => 24.7150, 'lng' => 46.6760, 'gps_enabled' => true,
            'online_since' => $online ? now() : null, 'last_seen_at' => now(),
        ]);

        return $driver;
    }

    private function asDriver(Driver $driver): static
    {
        return $this->actingAs($driver, 'driver_api');
    }

    private function fund(User $user, int $minor): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($user, $this->saudi), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup, ['idempotency_key' => 'fund:'.$user->id]);
    }
}
