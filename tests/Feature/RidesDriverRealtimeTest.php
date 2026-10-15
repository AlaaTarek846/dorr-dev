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
use Modules\Rides\Events\DriverChanged;
use Modules\Rides\Events\RideChanged;
use Modules\Rides\Events\RideDriverMoved;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * The captain app runs on real-time and push, not on asking the server every few seconds: its own
 * realtime-config (Pusher + the captain's OneSignal app), the same notification list and wallet as the
 * rider's, and a message on the captain's own channel whenever a request or a ride changes for them.
 */
class RidesDriverRealtimeTest extends TestCase
{
    private Country $saudi;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        config(['rides.regulator_enforced' => false, 'broadcasting.default' => 'null']);
        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2])->id,
        ]);
    }

    public function test_the_captain_gets_its_own_realtime_and_push_settings(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'abc123',
            'broadcasting.connections.pusher.options' => ['cluster' => 'eu', 'useTLS' => true],
            'services.onesignal.app_id' => 'shared-app',
            'services.onesignal.rest_api_key' => 'shared-rest',
        ]);
        $driver = $this->driver();
        $this->getJson('/api/driver/v1/realtime-config')->assertUnauthorized();

        $this->actingAs($driver, 'driver_api')->getJson('/api/driver/v1/realtime-config')->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.key', 'abc123')->assertJsonPath('data.cluster', 'eu')
            ->assertJsonPath('data.onesignal_app_id', 'shared-app')->assertJsonMissingPath('data.secret')
            ->assertJsonPath('data.channel', 'private-Modules.Driver.Models.Driver.'.$driver->id);

        // Its own OneSignal app, once both its keys are set.
        config(['services.onesignal.driver_app_id' => 'captain-app', 'services.onesignal.driver_rest_api_key' => 'captain-rest']);
        $this->actingAs($driver, 'driver_api')->getJson('/api/driver/v1/realtime-config')->assertOk()->assertJsonPath('data.onesignal_app_id', 'captain-app');
    }

    public function test_the_captain_pushes_go_through_the_captains_own_onesignal_app_at_top_priority(): void
    {
        config([
            'services.onesignal.app_id' => 'shared-app', 'services.onesignal.rest_api_key' => 'shared-rest',
            'services.onesignal.driver_app_id' => 'captain-app', 'services.onesignal.driver_rest_api_key' => 'captain-rest',
        ]);
        $this->withoutDefer();
        $driver = $this->driver();
        $rider = User::query()->create(['name' => 'Sara', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $driver->notificationDevices()->create(['player_id' => 'captain-device-1']);
        $rider->notificationDevices()->create(['player_id' => 'rider-device-1']);

        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            if (str_contains($request->url(), 'onesignal.com')) {
                $sent[] = ['auth' => $request->header('Authorization')[0] ?? null, 'body' => $request->data()];
            }

            return Http::response(['id' => 'x'], 200);
        });

        app(\App\Services\Notifications\NotificationCenter::class)->send($driver, 'ride.offer', 'ride_offer_title', 'ride_offer_body', ['distance' => '1', 'amount' => '10'], ['type' => 'ride_offer'], true, ['priority' => 10, 'ttl' => 15]);
        app(\App\Services\Notifications\NotificationCenter::class)->send($rider, 'ride.accepted', 'ride_accepted_title', 'ride_accepted_body', ['name' => 'K', 'car' => 'C', 'plate' => 'P', 'minutes' => '3'], ['type' => 'ride']);

        $byDevice = collect($sent)->keyBy(fn ($s) => $s['body']['include_player_ids'][0]);
        $this->assertSame('captain-app', $byDevice['captain-device-1']['body']['app_id']);
        $this->assertSame('Basic captain-rest', $byDevice['captain-device-1']['auth']);
        $this->assertSame(10, $byDevice['captain-device-1']['body']['priority']);
        $this->assertSame(15, $byDevice['captain-device-1']['body']['ttl']);
        $this->assertSame('shared-app', $byDevice['rider-device-1']['body']['app_id'], 'riders keep the shared app');
    }

    public function test_the_captain_has_the_same_notification_list_and_wallet(): void
    {
        $driver = $this->driver();
        $this->getJson('/api/driver/v1/wallet')->assertUnauthorized();
        $this->actingAs($driver, 'driver_api')->getJson('/api/driver/v1/notifications/unread-count')->assertOk();
        $this->actingAs($driver, 'driver_api')->postJson('/api/driver/v1/notifications/devices', ['player_id' => 'abc-123'])->assertSuccessful();

        $wallet = $this->actingAs($driver, 'driver_api')->getJson('/api/driver/v1/wallet')->assertOk()->json('data');
        $this->assertSame('SAR', $wallet['currency_code']);
        $this->assertSame(0, $wallet['total_minor']);
        $this->actingAs($driver, 'driver_api')->getJson('/api/driver/v1/wallet/transactions')->assertOk();
        $this->actingAs($driver, 'driver_api')->getJson('/api/driver/v1/wallet/withdrawal-methods')->assertOk();
        $this->actingAs($driver, 'driver_api')->getJson('/api/driver/v1/wallet/withdrawals')->assertOk();
    }

    public function test_a_request_and_its_answer_reach_the_captains_own_channel(): void
    {
        Event::fake([RideChanged::class, RideDriverMoved::class, DriverChanged::class]);
        $rider = User::query()->create(['name' => 'Sara', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 24.7136, 'lng' => 46.6753, 'status' => true, 'sort_order' => 1]);
        $economy = RideCategory::query()->create(['slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'flags' => ['cash_allowed' => true]]);
        foreach (['rides', 'category.economy', 'cash'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        RideFeatures::forget();
        RidePolicies::forget();
        CityResolver::forget();
        app(RidePolicies::class)->save($this->saudi->id, null, null, $economy->id, ['price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500]);
        $driver = $this->driver();
        DriverPresence::query()->create(['driver_id' => $driver->id, 'status' => PresenceStatus::Online, 'lat' => 24.7150, 'lng' => 46.6760, 'gps_enabled' => true, 'online_since' => now(), 'last_seen_at' => now()]);

        $quote = $this->actingAs($rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', [
            'origin' => ['lat' => 24.7136, 'lng' => 46.6753], 'destination' => ['lat' => 24.75, 'lng' => 46.70],
        ])->json('data.0');
        $uuid = $this->postJson('/api/mobile/v1/rides', ['quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => 'cash'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->json('data.uuid');

        // The captain is told on their own channel as soon as the request is theirs…
        Event::assertDispatched(DriverChanged::class, fn (DriverChanged $e) => $e->driverId === $driver->id && $e->rideUuid === $uuid);

        // …and again when the rider cancels (the card must go away).
        $this->actingAs($rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/cancel")->assertOk();
        Event::assertDispatched(DriverChanged::class, fn (DriverChanged $e) => $e->driverId === $driver->id && $e->event === 'canceled');
        $this->assertSame('private-Modules.Driver.Models.Driver.'.$driver->id, (new DriverChanged($driver->id, $uuid, 'x'))->broadcastOn()->name);
    }

    private function driver(): Driver
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
            'year' => 2022, 'body_type' => 'sedan', 'seats' => 4, 'plate_letters' => 'ا ب ج', 'plate_number' => '1234', 'plate_type' => 1,
            'status' => VehicleStatus::Approved, 'is_active' => true,
        ]);

        return $driver;
    }
}
