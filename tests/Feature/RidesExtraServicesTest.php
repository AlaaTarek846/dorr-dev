<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Enums\VehicleStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Driver\Models\Vehicle;
use Modules\Driver\Models\VehicleColor;
use Modules\Driver\Models\VehicleMake;
use Modules\Driver\Models\VehicleModel;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/** Boats & yachts (O-25), find a driver (O-26c) and driving lessons (O-26b): the money paths end to end. */
class RidesExtraServicesTest extends TestCase
{
    private Country $saudi;

    private Driver $captain;

    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['broadcasting.default' => 'null']);
        $this->seed(FinancialCategorySeeder::class);

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2])->id,
        ]);
        foreach (['rides', 'find_driver', 'driving_lessons'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        RideFeatures::forget();
        RidePolicies::forget();

        $this->captain = Driver::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Captain Ali', 'phone' => '+966550000099', 'phone_verified_at' => now(),
            'country_id' => $this->saudi->id, 'status' => DriverStatus::Active,
        ]);
        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_a_yacht_hire_is_booked_by_the_hour_never_overlaps_gets_more_time_and_pays_the_captain(): void
    {
        $marine = RideCategory::query()->create(['slug' => 'marine', 'pricing_strategy' => 'time', 'status' => true, 'sort_order' => 1, 'capacity_max' => 12, 'flags' => []]);
        app(RidePolicies::class)->save($this->saudi->id, null, null, $marine->id, ['price.commission_type' => 'percent', 'price.commission_value' => 1000]);
        $this->vehicle('yacht', 10);
        $this->fund(100000);

        $listing = $this->actingAs($this->captain, 'driver_api')->postJson('/api/driver/v1/marine/listings', [
            'kind' => 'rental', 'title' => 'Sunset yacht', 'marina' => ['lat' => 21.5, 'lng' => 39.1, 'address' => 'Jeddah marina'],
            'amenities' => ['life_jackets', 'music'], 'hour_price_minor' => 20000, 'min_hours' => 2,
        ])->assertCreated()->assertJsonPath('data.capacity', 10)->json('data');

        $as = $this->actingAs($this->rider, 'user_api');
        $start = now()->addDays(2)->startOfHour();
        $booking = $as->postJson("/api/mobile/v1/rides/marine/listings/{$listing['uuid']}/book", ['mode' => 'rental', 'guests' => 4, 'starts_at' => $start->toIso8601String(), 'hours' => 2], ['Idempotency-Key' => 'm-1'])
            ->assertCreated()->assertJsonPath('data.amount_minor', 40000)->assertJsonPath('data.status', 'unpaid')->json('data');
        // O-67: paid on the payment screen.
        $this->pay('marine_booking', $booking['uuid']);
        $this->assertSame(60000, $this->balance($this->rider));
        $booking = $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/marine/bookings/{$booking['uuid']}")->assertJsonPath('data.status', 'confirmed')->json('data');

        // The same boat an hour later overlaps: refused.
        $as->postJson("/api/mobile/v1/rides/marine/listings/{$listing['uuid']}/book", ['mode' => 'rental', 'guests' => 2, 'starts_at' => $start->copy()->addHour()->toIso8601String(), 'hours' => 2], ['Idempotency-Key' => 'm-2'])
            ->assertStatus(409)->assertJsonPath('error_code', 'rides_marine_slot_taken');

        // R-42: one more hour, the captain agrees — charged then.
        $as->postJson("/api/mobile/v1/rides/marine/bookings/{$booking['uuid']}/extend", ['hours' => 1])->assertOk()->assertJsonPath('data.extension.status', 'pending');
        $this->actingAs($this->captain, 'driver_api')->postJson("/api/driver/v1/marine/bookings/{$booking['uuid']}/extension", ['approve' => true])
            ->assertOk()->assertJsonPath('data.extension_minor', 20000);
        $this->assertSame(40000, $this->balance($this->rider));

        // Boarded by the QR, then finished: 60,000 less 10%.
        $this->actingAs($this->captain, 'driver_api')->postJson('/api/driver/v1/marine/board', ['qr' => $booking['qr']])->assertOk()->assertJsonPath('data.status', 'boarded');
        $this->actingAs($this->captain, 'driver_api')->postJson("/api/driver/v1/marine/bookings/{$booking['uuid']}/finish")->assertOk();
        $this->assertSame(54000, $this->balance($this->captain));

        // Each side heard every step.
        $this->assertEqualsCanonicalizing(['marine.confirmed', 'marine.extension_approved', 'marine.boarded', 'marine.completed'], $this->events($this->rider));
        $this->assertEqualsCanonicalizing(['marine.booked', 'marine.extension_asked', 'marine.earning'], $this->events($this->captain));
    }

    public function test_find_a_driver_the_nearest_online_captain_accepts_drives_and_is_paid(): void
    {
        app(RidePolicies::class)->save($this->saudi->id, null, null, null, ['hire.hour_price_minor' => 4000, 'hire.day_price_minor' => 25000, 'hire.commission_bp' => 2000]);
        DriverPresence::query()->create(['driver_id' => $this->captain->id, 'status' => 'online', 'lat' => 24.71, 'lng' => 46.67, 'gps_enabled' => true, 'last_seen_at' => now()]);
        $this->fund(20000);

        $as = $this->actingAs($this->rider, 'user_api');
        $as->getJson('/api/mobile/v1/rides/hire/quote?lat=24.7136&lng=46.6753&unit=hour&quantity=3')->assertOk()->assertJsonPath('data.amount_minor', 12000);
        $hire = $as->postJson('/api/mobile/v1/rides/hire', [
            'pickup' => ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'], 'unit' => 'hour', 'quantity' => 3, 'transmission' => 'manual', 'car' => 'Camry white',
        ], ['Idempotency-Key' => 'h-1'])->assertCreated()->assertJsonPath('data.status', 'unpaid')->json('data');
        // O-67: paid on the payment screen — then it's open to the captains.
        $this->actingAs($this->captain, 'driver_api')->getJson('/api/driver/v1/hire/open')->assertOk()->assertJsonCount(0, 'data');
        $this->pay('driver_hire', $hire['uuid']);
        $this->assertSame(8000, $this->balance($this->rider));
        $as = $this->actingAs($this->rider, 'user_api');
        $as->getJson("/api/mobile/v1/rides/hire/{$hire['uuid']}")->assertJsonPath('data.status', 'open');
        $as->postJson('/api/mobile/v1/rides/hire', ['pickup' => ['lat' => 24.7, 'lng' => 46.6, 'address' => 'x'], 'unit' => 'hour', 'quantity' => 2], ['Idempotency-Key' => 'h-2'])
            ->assertStatus(409)->assertJsonPath('error_code', 'rides_hire_already_open');

        $cap = $this->actingAs($this->captain, 'driver_api');
        $cap->getJson('/api/driver/v1/hire/open')->assertOk()->assertJsonPath('data.0.uuid', $hire['uuid'])->assertJsonPath('data.0.rider.phone', null);
        $cap->postJson("/api/driver/v1/hire/{$hire['uuid']}/accept")->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.rider.phone', '+966500000001');
        foreach (['arrived', 'started', 'completed'] as $to) {
            $cap->postJson("/api/driver/v1/hire/{$hire['uuid']}/move", ['to' => $to])->assertOk()->assertJsonPath('data.status', $to);
        }
        $this->assertSame(12000 - 2400, $this->balance($this->captain));

        $this->assertEqualsCanonicalizing(['hire.accepted', 'hire.arrived', 'hire.started', 'hire.completed'], $this->events($this->rider));
        $this->assertEqualsCanonicalizing(['hire.new', 'hire.earning'], $this->events($this->captain));
    }

    public function test_a_learner_buys_a_pack_books_a_lesson_the_trainer_is_paid_per_lesson_and_the_rest_is_refunded(): void
    {
        app(RidePolicies::class)->save($this->saudi->id, null, null, null, ['lessons.commission_bp' => 2000]);
        $this->fund(50000);

        $pack = $this->actingAs($this->captain, 'driver_api')->postJson('/api/driver/v1/lessons/packs', [
            'title' => 'Beginner 4', 'transmission' => 'automatic', 'lessons' => 4, 'minutes_per_lesson' => 60, 'price_minor' => 40000,
            'own_car' => true, 'area' => ['lat' => 24.71, 'lng' => 46.67, 'address' => 'Riyadh north'],
        ])->assertCreated()->assertJsonPath('data.per_lesson_minor', 10000)->json('data');

        $as = $this->actingAs($this->rider, 'user_api');
        $as->getJson('/api/mobile/v1/rides/lessons/packs?lat=24.7136&lng=46.6753')->assertOk()->assertJsonPath('data.0.uuid', $pack['uuid']);
        $enrollment = $as->postJson("/api/mobile/v1/rides/lessons/packs/{$pack['uuid']}/enroll", [], ['Idempotency-Key' => 'l-1'])->assertCreated()->assertJsonPath('data.status', 'unpaid')->json('data');
        $this->pay('lesson_pack', $enrollment['uuid']);
        $this->assertSame(10000, $this->balance($this->rider));
        $as = $this->actingAs($this->rider, 'user_api');

        $booked = $as->postJson("/api/mobile/v1/rides/lessons/enrollments/{$enrollment['uuid']}/sessions", [
            'starts_at' => now()->addDay()->startOfHour()->toIso8601String(), 'pickup' => ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Home'],
        ])->assertCreated()->assertJsonPath('data.lessons_left', 3)->json('data');
        $session = $booked['sessions'][0]['uuid'];

        $cap = $this->actingAs($this->captain, 'driver_api');
        $cap->postJson("/api/driver/v1/lessons/sessions/$session/answer", ['approve' => true])->assertOk()->assertJsonPath('data.status', 'confirmed');
        $cap->postJson("/api/driver/v1/lessons/sessions/$session/done")->assertStatus(409);
        $this->travel(2)->days();
        $cap->postJson("/api/driver/v1/lessons/sessions/$session/done")->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertSame(10000 - 2000, $this->balance($this->captain));

        // The learner stops: the three lessons not taken come back.
        $as->postJson("/api/mobile/v1/rides/lessons/enrollments/{$enrollment['uuid']}/quit")->assertOk()->assertJsonPath('data.refunded_minor', 30000);
        $this->assertSame(40000, $this->balance($this->rider));

        $this->assertEqualsCanonicalizing(['lesson.purchased', 'lesson.confirmed', 'lesson.done'], $this->events($this->rider));
        $this->assertEqualsCanonicalizing(['lesson.enrolled', 'lesson.requested', 'lesson.earning', 'lesson.quit'], $this->events($this->captain));
    }

    public function test_unpaid_bookings_lapse_on_time_and_bookings_soon_are_reminded_once(): void
    {
        app(RidePolicies::class)->save($this->saudi->id, null, null, null, ['hire.hour_price_minor' => 4000, 'hire.min_hours' => 1]);
        $this->fund(100000);
        RideCategory::query()->create(['slug' => 'marine', 'pricing_strategy' => 'time', 'status' => true, 'sort_order' => 1, 'capacity_max' => 12, 'flags' => []]);
        $this->vehicle('yacht', 10);
        $listing = $this->actingAs($this->captain, 'driver_api')->postJson('/api/driver/v1/marine/listings', [
            'kind' => 'rental', 'title' => 'Sunset yacht', 'marina' => ['lat' => 21.5, 'lng' => 39.1, 'address' => 'Jeddah marina'],
            'amenities' => [], 'hour_price_minor' => 20000, 'min_hours' => 1,
        ])->assertCreated()->json('data');
        $as = $this->actingAs($this->rider, 'user_api');
        $as->postJson("/api/mobile/v1/rides/marine/listings/{$listing['uuid']}/book", ['mode' => 'rental', 'guests' => 2, 'starts_at' => now()->addDays(2)->startOfHour()->toIso8601String(), 'hours' => 2], ['Idempotency-Key' => 'm'])->assertCreated();
        $as->postJson('/api/mobile/v1/rides/hire', ['pickup' => ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'], 'unit' => 'hour', 'quantity' => 2], ['Idempotency-Key' => 'h'])->assertCreated();

        $this->travel(\Modules\Rides\Services\Bus\BusService::HOLD_MINUTES + 1)->minutes();
        $this->artisan('rides:services-sweep')->assertSuccessful();
        $this->assertEqualsCanonicalizing(['marine.lapsed', 'hire.lapsed'], $this->events($this->rider));
        $this->assertSame(0, \Modules\Rides\Models\MarineBooking::query()->where('status', 'unpaid')->count());

        // A boat booked for 40 minutes from now, paid: both sides reminded once.
        $uuid = $as->postJson("/api/mobile/v1/rides/marine/listings/{$listing['uuid']}/book", ['mode' => 'rental', 'guests' => 2, 'starts_at' => now()->addMinutes(40)->toIso8601String(), 'hours' => 1], ['Idempotency-Key' => 'm2'])->assertCreated()->json('data.uuid');
        $this->pay('marine_booking', $uuid);
        $this->artisan('rides:services-sweep')->assertSuccessful();
        $this->artisan('rides:services-sweep')->assertSuccessful();
        $this->assertSame(1, collect($this->events($this->rider))->filter(fn ($e) => $e === 'marine.reminder')->count());
        $this->assertSame(1, collect($this->events($this->captain))->filter(fn ($e) => $e === 'marine.reminder')->count());
    }

    public function test_a_trainer_edits_and_removes_a_pack_and_a_bought_one_is_only_archived(): void
    {
        // O-62: edit freely while nobody bought it; with learners only the words change, and removing archives it.
        $make = fn () => $this->actingAs($this->captain, 'driver_api')->postJson('/api/driver/v1/lessons/packs', [
            'title' => 'Pack', 'transmission' => 'manual', 'lessons' => 2, 'minutes_per_lesson' => 60, 'price_minor' => 20000,
            'area' => ['lat' => 24.71, 'lng' => 46.67, 'address' => 'Riyadh'],
        ])->assertCreated()->json('data.uuid');
        $cap = fn () => $this->actingAs($this->captain, 'driver_api');

        $free = $make();
        $cap()->putJson("/api/driver/v1/lessons/packs/$free", ['price_minor' => 30000, 'title' => 'Pack 2'])->assertOk()->assertJsonPath('data.price_minor', 30000);
        $cap()->deleteJson("/api/driver/v1/lessons/packs/$free")->assertOk();
        $this->assertNull(\Modules\Rides\Models\LessonPack::query()->where('uuid', $free)->first());

        $bought = $make();
        $this->fund(50000);
        $this->pay('lesson_pack', $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/lessons/packs/$bought/enroll", [], ['Idempotency-Key' => 'e-1'])->assertCreated()->json('data.uuid'));
        $cap()->putJson("/api/driver/v1/lessons/packs/$bought", ['price_minor' => 1])->assertStatus(409)->assertJsonPath('error_code', 'rides_lessons_pack_has_learners');
        $cap()->putJson("/api/driver/v1/lessons/packs/$bought", ['title' => 'Renamed'])->assertOk()->assertJsonPath('data.title', 'Renamed');
        $cap()->deleteJson("/api/driver/v1/lessons/packs/$bought")->assertOk()->assertJsonPath('data.status', 'archived');
        $cap()->getJson('/api/driver/v1/lessons/packs')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_maps_surroundings_free_cars_for_the_rider_and_hot_areas_for_the_captain(): void
    {
        // O-59: a free online captain nearby shows as a car (an alias, never his id); O-58: requests make hot areas.
        DriverPresence::query()->create(['driver_id' => $this->captain->id, 'status' => 'online', 'lat' => 24.7140, 'lng' => 46.6760, 'gps_enabled' => true, 'last_seen_at' => now()]);
        $cars = $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/nearby-cars?lat=24.7136&lng=46.6753')->assertOk()->json('data');
        $this->assertCount(1, $cars['cars']);
        $this->assertNotSame((string) $this->captain->id, $cars['cars'][0]['car']);
        $this->assertCount(9, $cars['cells']);

        $this->actingAs($this->captain, 'driver_api')->getJson('/api/driver/v1/demand-heat?lat=24.7136&lng=46.6753')->assertOk()->assertJsonPath('data', []);
    }

    public function test_place_search_suggests_from_two_letters_with_the_admins_provider(): void
    {
        // O-57: Mapbox's geocoder with autocomplete; the answer is [{name, address, lat, lng}].
        \Modules\Rides\Models\RideSetting::current()->forceFill(['map_provider' => 'mapbox', 'mapbox_token' => 'pk.test'])->save();
        // setUp fakes every call with an empty answer — a fresh client with Mapbox's answer instead.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.mapbox.com/*' => Http::response(['features' => [
            ['text' => 'الدقي', 'place_name' => 'الدقي، الجيزة', 'center' => [31.21, 30.04]],
        ]])]);
        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/places/suggest?q=الدق&lat=30.05&lng=31.2')->assertOk()
            ->assertJsonPath('data.0.name', 'الدقي')->assertJsonPath('data.0.lat', 30.04);
        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/places/suggest?q=ا')->assertOk()->assertJsonPath('data', []);
    }

    private function vehicle(string $body, int $seats): void
    {
        $make = VehicleMake::query()->create(['status' => true]);
        $model = VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => $body, 'seats' => $seats, 'status' => true]);
        Vehicle::query()->create([
            'uuid' => (string) Str::uuid(), 'driver_id' => $this->captain->id, 'country_id' => $this->saudi->id, 'make_id' => $make->id, 'model_id' => $model->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#FFFFFF', 'status' => true])->id,
            'year' => 2022, 'body_type' => $body, 'seats' => $seats, 'plate_letters' => 'ا ب ج', 'plate_number' => '7002', 'plate_type' => 1,
            'status' => VehicleStatus::Approved, 'is_active' => true,
        ]);
    }

    private function fund(int $minor): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($this->rider, $this->saudi), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup, ['idempotency_key' => 'fund:'.$this->rider->id]);
    }

    /** @return list<string> the events of the notifications this person got */
    private function events(User|Driver $person): array
    {
        return $person->notifications()->get()->map(fn ($n) => $n->data['event'] ?? null)->all();
    }

    /** O-67: the app's one payment screen — the wallet, with its PIN. */
    private function pay(string $purpose, string $uuid): void
    {
        app(\Modules\Wallet\Services\PinService::class)->set($this->rider, '1234');
        $checkout = $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/wallet/checkouts', ['purpose' => $purpose, 'reference' => ['booking_uuid' => $uuid]], ['X-Country' => 'SA'])->assertCreated()->json('data.id');
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$checkout}/pay", ['method' => 'wallet'], ['X-Country' => 'SA', 'X-Wallet-Pin' => '1234'])->assertOk()->assertJsonPath('data.status', 'paid');
    }

    private function balance(User|Driver $owner): int
    {
        $w = app(WalletService::class)->firstOrCreateWallet($owner, $this->saudi)->fresh();

        return $w->spend_only_minor + $w->withdrawable_minor;
    }
}
