<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Enums\VehicleStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\Vehicle;
use Modules\Driver\Models\VehicleColor;
use Modules\Driver\Models\VehicleMake;
use Modules\Driver\Models\VehicleModel;
use Modules\Rides\Events\BusSeatsChanged;
use Modules\Rides\Models\BusBookingSeat;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/** Bus booking (docs/rides_tasks.md O-20..O-24). */
class RidesBusTest extends TestCase
{
    private Country $saudi;

    private Driver $captain;

    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Event::fake([BusSeatsChanged::class]);
        config(['broadcasting.default' => 'null']);
        $this->seed(FinancialCategorySeeder::class);

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2])->id,
        ]);
        $bus = RideCategory::query()->create(['slug' => 'bus', 'pricing_strategy' => 'seat', 'status' => true, 'sort_order' => 1, 'capacity_max' => 50, 'flags' => []]);
        RidePolicies::forget();
        app(RidePolicies::class)->save($this->saudi->id, null, null, $bus->id, ['price.commission_type' => 'percent', 'price.commission_value' => 1000]);

        $this->captain = Driver::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Captain Bus', 'phone' => '+966550000099', 'phone_verified_at' => now(),
            'country_id' => $this->saudi->id, 'status' => DriverStatus::Active,
        ]);
        $make = VehicleMake::query()->create(['status' => true]);
        $model = VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => 'bus', 'seats' => 12, 'status' => true]);
        Vehicle::query()->create([
            'uuid' => (string) Str::uuid(), 'driver_id' => $this->captain->id, 'country_id' => $this->saudi->id, 'make_id' => $make->id, 'model_id' => $model->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#FFFFFF', 'status' => true])->id,
            'year' => 2022, 'body_type' => 'bus', 'seats' => 12, 'plate_letters' => 'ا ب ج', 'plate_number' => '7001', 'plate_type' => 1,
            'status' => VehicleStatus::Approved, 'is_active' => true,
        ]);
        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_a_captain_publishes_a_trip_a_rider_finds_it_books_seats_and_the_captain_boards_and_is_paid(): void
    {
        $this->fund(30000);
        $trip = $this->actingAs($this->captain, 'driver_api')->postJson('/api/driver/v1/bus/trips', [
            'origin' => ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Riyadh'],
            'destination' => ['lat' => 21.4858, 'lng' => 39.1925, 'address' => 'Jeddah'],
            'depart_at' => now()->addDay()->toIso8601String(),
            'return_at' => now()->addDays(3)->toIso8601String(),
            'price_outbound_minor' => 5000, 'price_return_minor' => 4500, 'price_round_minor' => 9000,
        ])->assertCreated()->assertJsonPath('data.seats_total', 12)->json('data');

        // O-22: by where I leave from only, by where I go only, and both — and the coming-back leg the other way.
        $as = $this->actingAs($this->rider, 'user_api');
        $as->getJson('/api/mobile/v1/rides/bus/trips?from_lat=24.714&from_lng=46.676')->assertOk()->assertJsonPath('data.0.uuid', $trip['uuid'])->assertJsonPath('data.0.leg', 'outbound');
        $as->getJson('/api/mobile/v1/rides/bus/trips?to_lat=21.486&to_lng=39.193')->assertOk()->assertJsonPath('data.0.leg', 'outbound');
        $as->getJson('/api/mobile/v1/rides/bus/trips?from_lat=21.486&from_lng=39.193&to_lat=24.714&to_lng=46.676')->assertOk()->assertJsonPath('data.0.leg', 'return');
        $as->getJson('/api/mobile/v1/rides/bus/trips?from_lat=30.0&from_lng=31.0')->assertOk()->assertJsonCount(0, 'data');

        // O-23: going and back, two seats each way — 2 × 90.00. O-67: held «unpaid», then paid on the payment screen.
        $booking = $as->postJson("/api/mobile/v1/rides/bus/trips/{$trip['uuid']}/book", ['trip_type' => 'round', 'seats_outbound' => [1, 2], 'seats_return' => [3, 4]], ['Idempotency-Key' => 'k-1'])
            ->assertCreated()->assertJsonPath('data.amount_minor', 18000)->assertJsonPath('data.status', 'unpaid')->assertJsonCount(4, 'data.tickets')->json('data');
        $this->assertSame(30000, $this->balance($this->rider));
        $this->pay($this->rider, 'bus_booking', $booking['uuid']);
        $this->assertSame(30000 - 18000, $this->balance($this->rider));
        $as->getJson("/api/mobile/v1/rides/bus/bookings/{$booking['uuid']}")->assertJsonPath('data.status', 'confirmed');
        $as->getJson("/api/mobile/v1/rides/bus/trips/{$trip['uuid']}")->assertJsonPath('data.taken.outbound', [1, 2])->assertJsonPath('data.taken.return', [3, 4]);

        // Nobody else gets those seats.
        $other = User::query()->create(['name' => 'Omar', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->fund(30000, $other);
        $this->actingAs($other, 'user_api')->postJson("/api/mobile/v1/rides/bus/trips/{$trip['uuid']}/book", ['trip_type' => 'outbound', 'seats_outbound' => [2]], ['Idempotency-Key' => 'k-2'])
            ->assertStatus(409)->assertJsonPath('error_code', 'rides_bus_seat_taken');

        // O-24: the captain sees who booked, boards by the ticket's QR.
        $this->actingAs($this->captain, 'driver_api')->getJson("/api/driver/v1/bus/trips/{$trip['uuid']}")->assertJsonPath('data.passengers.0.name', 'Sara Ahmed');
        $qr = BusBookingSeat::query()->where('leg', 'outbound')->where('seat_no', 1)->value('qr_token');
        $this->actingAs($this->captain, 'driver_api')->postJson("/api/driver/v1/bus/trips/{$trip['uuid']}/board", ['qr' => $qr])->assertOk()->assertJsonPath('data.seat', 1);

        // Ended: the tickets less 10% go to the captain. O-68: it leaves at its time, not a day early.
        $this->actingAs($this->captain, 'driver_api')->postJson("/api/driver/v1/bus/trips/{$trip['uuid']}/move", ['to' => 'departed'])->assertStatus(409);
        $this->travelTo(now()->addDay());
        foreach (['departed', 'completed'] as $to) {
            $this->actingAs($this->captain, 'driver_api')->postJson("/api/driver/v1/bus/trips/{$trip['uuid']}/move", ['to' => $to])->assertOk();
        }
        $this->assertSame(18000 - 1800, $this->balance($this->captain));
        $this->assertNotNull($booking['uuid']);
    }

    public function test_a_ticket_is_refunded_in_full_before_the_window_and_the_seats_are_free_again(): void
    {
        $this->fund(10000);
        $this->actingAs($this->captain, 'driver_api')->postJson('/api/driver/v1/bus/trips', [
            'origin' => ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Riyadh'], 'destination' => ['lat' => 21.4858, 'lng' => 39.1925, 'address' => 'Jeddah'],
            'depart_at' => now()->addDay()->toIso8601String(), 'price_outbound_minor' => 5000,
        ])->assertCreated();
        $trip = \Modules\Rides\Models\BusTrip::query()->sole();
        $uuid = $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/bus/trips/{$trip->uuid}/book", ['trip_type' => 'outbound', 'seats_outbound' => [5]], ['Idempotency-Key' => 'k'])->json('data.uuid');
        $this->pay($this->rider, 'bus_booking', $uuid);
        $this->assertSame(5000, $this->balance($this->rider));

        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/bus/bookings/{$uuid}/cancel")->assertOk()->assertJsonPath('data.status', 'canceled');
        $this->assertSame(10000, $this->balance($this->rider));
        $this->assertSame([], $trip->fresh()->takenSeats('outbound'));
    }

    public function test_unpaid_seats_are_held_then_freed_when_the_payment_never_comes_and_a_coupon_takes_off_its_part(): void
    {
        $this->fund(10000);
        $this->actingAs($this->captain, 'driver_api')->postJson('/api/driver/v1/bus/trips', [
            'origin' => ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Riyadh'], 'destination' => ['lat' => 21.4858, 'lng' => 39.1925, 'address' => 'Jeddah'],
            'depart_at' => now()->addDay()->toIso8601String(), 'price_outbound_minor' => 5000,
        ])->assertCreated();
        $trip = \Modules\Rides\Models\BusTrip::query()->sole();
        $as = $this->actingAs($this->rider, 'user_api');
        $as->postJson("/api/mobile/v1/rides/bus/trips/{$trip->uuid}/book", ['trip_type' => 'outbound', 'seats_outbound' => [7]], ['Idempotency-Key' => 'k1'])->assertCreated();
        $this->assertSame([7], $trip->fresh()->takenSeats('outbound'));

        // Not paid within the hold: the seat is free again and the booking is gone from my tickets.
        $this->travel(\Modules\Rides\Services\Bus\BusService::HOLD_MINUTES + 1)->minutes();
        $as->getJson("/api/mobile/v1/rides/bus/trips/{$trip->uuid}")->assertJsonPath('data.taken.outbound', []);
        $as->getJson('/api/mobile/v1/rides/bus/bookings')->assertJsonCount(0, 'data');
        $this->assertSame(10000, $this->balance($this->rider));

        // Booked again — and paid with a coupon: only the rest is taken.
        $uuid = $as->postJson("/api/mobile/v1/rides/bus/trips/{$trip->uuid}/book", ['trip_type' => 'outbound', 'seats_outbound' => [7]], ['Idempotency-Key' => 'k2'])->assertCreated()->json('data.uuid');
        \Modules\Wallet\Models\WalletCoupon::query()->create([
            'code' => 'BUS20', 'kind' => 'fixed', 'value' => 2000, 'usage_limit' => 1, 'used_count' => 0,
            'source' => 'admin', 'status' => 'active', 'starts_at' => now()->subMinute(), 'country_id' => $this->saudi->id,
        ]);
        app(\Modules\Wallet\Services\PinService::class)->set($this->rider, '1234');
        $checkout = $this->postJson('/api/mobile/v1/wallet/checkouts', ['purpose' => 'bus_booking', 'reference' => ['booking_uuid' => $uuid]], ['X-Country' => 'SA'])
            ->assertCreated()->assertJsonPath('data.coupons_allowed', true)->json('data.id');
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$checkout}/coupon", ['code' => 'BUS20'], ['X-Country' => 'SA'])->assertOk()->assertJsonPath('data.payable_minor', 3000);
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$checkout}/pay", ['method' => 'wallet'], ['X-Country' => 'SA', 'X-Wallet-Pin' => '1234'])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame(7000, $this->balance($this->rider));
        $as->getJson("/api/mobile/v1/rides/bus/bookings/{$uuid}")->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.discount_minor', 2000);
    }

    public function test_the_passenger_and_the_captain_chat_and_call_inside_the_app(): void
    {
        config(['chat.livekit.url' => 'wss://live.example.test', 'chat.livekit.api_key' => 'key', 'chat.livekit.api_secret' => 'secret-that-is-long-enough']);
        $this->fund(10000);
        $this->actingAs($this->captain, 'driver_api')->postJson('/api/driver/v1/bus/trips', [
            'origin' => ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Riyadh'], 'destination' => ['lat' => 21.4858, 'lng' => 39.1925, 'address' => 'Jeddah'],
            'depart_at' => now()->addDay()->toIso8601String(), 'price_outbound_minor' => 5000,
        ])->assertCreated();
        $trip = \Modules\Rides\Models\BusTrip::query()->sole();
        $uuid = $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/bus/trips/{$trip->uuid}/book", ['trip_type' => 'outbound', 'seats_outbound' => [3]], ['Idempotency-Key' => 'k'])->json('data.uuid');

        // Not paid yet: nothing to talk about.
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/bus/bookings/{$uuid}/messages", ['body' => 'hi'])->assertStatus(409);
        $this->pay($this->rider, 'bus_booking', $uuid);

        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/bus/bookings/{$uuid}/messages", ['body' => 'Where do I wait?', 'client_id' => 'c1'])->assertCreated();
        $this->actingAs($this->captain, 'driver_api')->getJson("/api/driver/v1/bus/trips/{$trip->uuid}")->assertJsonPath('data.passengers.0.unread', 1);
        $this->actingAs($this->captain, 'driver_api')->getJson("/api/driver/v1/bus/bookings/{$uuid}/messages")
            ->assertOk()->assertJsonPath('data.open', true)->assertJsonPath('data.messages.0.body', 'Where do I wait?')->assertJsonPath('data.messages.0.mine', false);
        $this->actingAs($this->captain, 'driver_api')->postJson("/api/driver/v1/bus/bookings/{$uuid}/messages", ['quick' => 'arrived'])->assertCreated();
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/bus/bookings/{$uuid}")->assertJsonPath('data.unread', 1);

        // The captain calls; the passenger answers and hangs up.
        $call = $this->actingAs($this->captain, 'driver_api')->postJson("/api/driver/v1/bus/bookings/{$uuid}/calls")->assertCreated()
            ->assertJsonPath('data.caller', 'driver')->assertJsonPath('data.join.url', 'wss://live.example.test')->json('data.uuid');
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/bus/bookings/{$uuid}")->assertJsonPath('data.call.status', 'ringing');
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/bus/bookings/{$uuid}/calls/{$call}/accept")->assertOk()->assertJsonPath('data.status', 'active');
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/bus/bookings/{$uuid}/calls/{$call}/end")->assertOk()->assertJsonPath('data.status', 'ended');

        // Another captain can't read it.
        $other = Driver::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Other', 'phone' => '+966550000077', 'phone_verified_at' => now(), 'country_id' => $this->saudi->id, 'status' => DriverStatus::Active]);
        $this->actingAs($other, 'driver_api')->getJson("/api/driver/v1/bus/bookings/{$uuid}/messages")->assertNotFound();
    }

    public function test_the_whole_cycle_tells_each_side_at_every_step(): void
    {
        $this->fund(10000);
        $this->actingAs($this->captain, 'driver_api')->postJson('/api/driver/v1/bus/trips', [
            'origin' => ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Riyadh'], 'destination' => ['lat' => 21.4858, 'lng' => 39.1925, 'address' => 'Jeddah'],
            'depart_at' => now()->addMinutes(40)->toIso8601String(), 'price_outbound_minor' => 5000,
        ])->assertCreated();
        $trip = \Modules\Rides\Models\BusTrip::query()->sole();

        // A hold never paid: let go by the sweep, and the rider is told.
        $other = User::query()->create(['name' => 'Omar', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->actingAs($other, 'user_api')->postJson("/api/mobile/v1/rides/bus/trips/{$trip->uuid}/book", ['trip_type' => 'outbound', 'seats_outbound' => [9]], ['Idempotency-Key' => 'o'])->assertCreated();

        $uuid = $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/bus/trips/{$trip->uuid}/book", ['trip_type' => 'outbound', 'seats_outbound' => [2]], ['Idempotency-Key' => 'k'])->json('data.uuid');
        $this->pay($this->rider, 'bus_booking', $uuid);
        $this->travel(\Modules\Rides\Services\Bus\BusService::HOLD_MINUTES + 1)->minutes();

        // The sweep: the lapsed hold, and one reminder (it leaves within the hour) — twice, still one.
        $this->artisan('rides:services-sweep')->assertSuccessful();
        $this->artisan('rides:services-sweep')->assertSuccessful();
        $this->assertSame(['bus.lapsed'], $this->events($other));
        $this->assertSame(1, collect($this->events($this->rider))->filter(fn ($e) => $e === 'bus.reminder')->count());
        $this->assertContains('bus.reminder', $this->events($this->captain));

        // On board, off, over.
        $qr = BusBookingSeat::query()->where('seat_no', 2)->value('qr_token');
        $this->actingAs($this->captain, 'driver_api')->postJson("/api/driver/v1/bus/trips/{$trip->uuid}/board", ['qr' => $qr])->assertOk();
        foreach (['departed', 'completed'] as $to) {
            $this->actingAs($this->captain, 'driver_api')->postJson("/api/driver/v1/bus/trips/{$trip->uuid}/move", ['to' => $to])->assertOk();
        }

        $this->assertEqualsCanonicalizing(['bus.booking_confirmed', 'bus.reminder', 'bus.boarded', 'bus.departed', 'bus.completed'], $this->events($this->rider));
        $this->assertEqualsCanonicalizing(['bus.booked', 'bus.reminder', 'bus.earning'], $this->events($this->captain));
    }

    /** @return list<string> the events of the notifications this person got */
    private function events(User|Driver $person): array
    {
        return $person->notifications()->get()->map(fn ($n) => $n->data['event'] ?? null)->all();
    }

    /** O-67: the app's one payment screen — the wallet, with its PIN. */
    private function pay(User $user, string $purpose, string $uuid): void
    {
        app(\Modules\Wallet\Services\PinService::class)->set($user, '1234');
        $checkout = $this->actingAs($user, 'user_api')->postJson('/api/mobile/v1/wallet/checkouts', ['purpose' => $purpose, 'reference' => ['booking_uuid' => $uuid]], ['X-Country' => 'SA'])->assertCreated()->json('data.id');
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$checkout}/pay", ['method' => 'wallet'], ['X-Country' => 'SA', 'X-Wallet-Pin' => '1234'])->assertOk()->assertJsonPath('data.status', 'paid');
    }

    private function fund(int $minor, ?User $user = null): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($user ?? $this->rider, $this->saudi), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup, ['idempotency_key' => 'fund:'.($user ?? $this->rider)->id]);
    }

    private function balance(User|Driver $owner): int
    {
        $w = app(WalletService::class)->firstOrCreateWallet($owner, $this->saudi)->fresh();

        return $w->spend_only_minor + $w->withdrawable_minor;
    }
}
