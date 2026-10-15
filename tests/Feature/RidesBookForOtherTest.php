<?php

namespace Tests\Feature;

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
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideEvent;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * R-13: booking a ride for someone else — the rider's positive consent, the captain seeing only the
 * person's first name, and that person hearing who is coming (in the app when on DORR, else by SMS).
 */
class RidesBookForOtherTest extends TestCase
{
    private const PICKUP = ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'];

    private const DROPOFF = ['lat' => 24.7500, 'lng' => 46.7000, 'address' => 'King Fahd Rd'];

    private const MONA = ['name' => '  Mona   Ali ', 'phone' => '+966 50 000 0009', 'consent' => true];

    private Country $saudi;

    private User $rider;

    private int $economyId;

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
        $this->switches(['rides', 'category.economy', 'cash', 'share', 'book_for_other']);
        $this->price([]);

        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_the_rider_books_for_someone_else_and_the_captain_sees_only_their_first_name(): void
    {
        $driver = $this->onlineDriver();
        $quote = $this->quote();

        // The rider's yes is never assumed, and "someone else" can't be the rider.
        $this->book($quote, ['beneficiary' => ['consent' => false] + self::MONA])->assertStatus(422)->assertJsonValidationErrors('beneficiary.consent');
        $this->book($quote, ['beneficiary' => ['name' => 'Mona']])->assertStatus(422)->assertJsonValidationErrors(['beneficiary.phone', 'beneficiary.consent']);
        $this->book($quote, ['beneficiary' => ['phone' => '+966 500 000 001'] + self::MONA])->assertStatus(422)->assertJsonPath('error_code', 'rides_beneficiary_is_you');

        $uuid = $this->book($quote, ['beneficiary' => self::MONA])->assertCreated()
            ->assertJsonPath('data.beneficiary', ['name' => 'Mona Ali', 'phone' => '+966500000009'])->json('data.uuid');
        $ride = Ride::query()->where('uuid', $uuid)->sole();
        $this->assertNotNull($ride->beneficiary['consented_at']);
        $this->assertTrue(RideEvent::query()->where('ride_id', $ride->id)->where('type', 'created')->sole()->payload['for_someone_else']);

        // The captain knows whom to pick up — by first name, never their number.
        $offer = $this->asDriver($driver)->getJson('/api/driver/v1/offers')->assertOk();
        $offer->assertJsonPath('data.0.beneficiary', ['name' => 'Mona']);
        $this->assertStringNotContainsString('500000009', $offer->getContent());
        $accepted = $this->asDriver($driver)->postJson('/api/driver/v1/offers/'.$offer->json('data.0.uuid').'/accept')->assertOk()
            ->assertJsonPath('data.beneficiary', ['name' => 'Mona'])->assertJsonPath('data.rider.name', 'Sara');
        $this->assertStringNotContainsString('500000009', $accepted->getContent());

        // Mona isn't on DORR: she's texted who is coming and the live link.
        $told = RideEvent::query()->where('ride_id', $ride->id)->where('type', 'beneficiary_notified')->sole();
        $this->assertSame(['channel' => 'sms', 'link' => true, 'pin' => false], $told->payload);

        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides')->assertOk()->assertJsonPath('data.items.0.beneficiary_name', 'Mona Ali');
    }

    public function test_a_beneficiary_on_dorr_hears_it_in_the_app_with_the_ride_code(): void
    {
        User::query()->create(['name' => 'Mona Ali', 'phone' => '+966500000009', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->price(['pin.required' => true]);
        $this->switches(['share'], false);
        $driver = $this->onlineDriver();

        $uuid = $this->book($this->quote(), ['beneficiary' => self::MONA])->assertCreated()->json('data.uuid');
        $offer = $this->asDriver($driver)->getJson('/api/driver/v1/offers')->assertOk()->json('data.0.uuid');
        $this->asDriver($driver)->postJson("/api/driver/v1/offers/$offer/accept")->assertOk()->assertJsonPath('data.needs_pin', true);

        // Sharing is off here, so no link — but she still learns who is coming and the code to read out.
        $ride = Ride::query()->where('uuid', $uuid)->sole();
        $told = RideEvent::query()->where('ride_id', $ride->id)->where('type', 'beneficiary_notified')->sole();
        $this->assertSame(['channel' => 'app', 'link' => false, 'pin' => true], $told->payload);
    }

    public function test_booking_for_someone_else_needs_its_switch(): void
    {
        $this->switches(['book_for_other'], false);
        $this->onlineDriver();
        $quote = $this->quote();

        $this->book($quote, ['beneficiary' => self::MONA])->assertForbidden()
            ->assertJsonPath('error_code', 'rides_feature_disabled')->assertJsonPath('data.feature', 'book_for_other');

        // Booking for oneself is untouched, and nobody is told anything.
        $uuid = $this->book($quote)->assertCreated()->assertJsonPath('data.beneficiary', null)->json('data.uuid');
        $this->assertFalse(RideEvent::query()->where('ride_id', Ride::query()->where('uuid', $uuid)->value('id'))->where('type', 'beneficiary_notified')->exists());
    }

    /** @param list<string> $features */
    private function switches(array $features, bool $enabled = true): void
    {
        foreach ($features as $feature) {
            RideFeatureRule::query()->updateOrCreate(['feature' => $feature, 'country_id' => $this->saudi->id], ['enabled' => $enabled]);
        }
        RideFeatures::forget();
        RidePolicies::forget();
        CityResolver::forget();
    }

    /** @param array<string, mixed> $values */
    private function price(array $values): void
    {
        app(RidePolicies::class)->save($this->saudi->id, null, null, $this->economyId, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
        ] + $values);
        RidePolicies::forget();
    }

    /** @return array<string, mixed> */
    private function quote(): array
    {
        return $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::PICKUP, 'destination' => self::DROPOFF])->assertOk()->json('data.0');
    }

    /**
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>  $extra
     */
    private function book(array $quote, array $extra = []): TestResponse
    {
        return $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides', ['quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => 'cash'] + $extra, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function onlineDriver(): Driver
    {
        $driver = Driver::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Captain One', 'phone' => '+966550000001', 'phone_verified_at' => now(),
            'country_id' => $this->saudi->id, 'status' => DriverStatus::Active,
        ]);
        $make = VehicleMake::query()->create(['status' => true]);
        $model = VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => 'sedan', 'seats' => 4, 'status' => true]);
        Vehicle::query()->create([
            'uuid' => (string) Str::uuid(), 'driver_id' => $driver->id, 'country_id' => $this->saudi->id, 'make_id' => $make->id, 'model_id' => $model->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#FFFFFF', 'status' => true])->id,
            'year' => 2022, 'body_type' => 'sedan', 'seats' => 4, 'plate_letters' => 'ا ب ج', 'plate_number' => '1001', 'plate_type' => 1,
            'status' => VehicleStatus::Approved, 'is_active' => true,
        ]);
        DriverPresence::query()->create([
            'driver_id' => $driver->id, 'status' => PresenceStatus::Online, 'lat' => 24.7300, 'lng' => 46.6900, 'gps_enabled' => true,
            'online_since' => now(), 'last_seen_at' => now(),
        ]);

        return $driver;
    }

    private function asDriver(Driver $driver): static
    {
        return $this->actingAs($driver, 'driver_api');
    }
}
