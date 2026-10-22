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
use Modules\Rides\Models\DriverRideProfile;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Models\RideSettlementItem;
use Modules\Rides\Models\RideTaxRule;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Enums\PaymentMethodType;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Models\FinancialEntry;
use Modules\Wallet\Models\PaymentMethod;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * Phase 1 of rides end to end (docs/rides-plan.md §6): the rider prices and books an Economy ride,
 * it is offered to the nearest driver, accepted, driven and ended — and the money lands where it
 * should, for a wallet ride and a cash one. Plus cancellations, a driver stepping back, the no-show,
 * open destination and ratings.
 */
class RidesFlowTest extends TestCase
{
    private const PICKUP = ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'];

    private const DROPOFF = ['lat' => 24.7500, 'lng' => 46.7000, 'address' => 'King Fahd Rd'];

    private Country $saudi;

    private DiscoverCity $riyadh;

    private User $rider;

    private int $plates = 0;

    /** @var array<string, mixed> url pattern => fake answer, for the one Http::fake below */
    private array $http = [];

    protected function setUp(): void
    {
        parent::setUp();

        // No Google key: routes use the free estimate. Pushes and broadcasts never leave the test.
        Http::fake(function ($request) {
            foreach ($this->http as $pattern => $answer) {
                if (Str::is('*'.$pattern.'*', $request->url())) {
                    return $answer;
                }
            }

            return Http::response([], 200);
        });
        Event::fake([RideChanged::class, RideDriverMoved::class]);
        config(['rides.regulator_enforced' => false, 'broadcasting.default' => 'null']);

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2])->id,
        ]);
        $this->riyadh = DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 24.7136, 'lng' => 46.6753, 'status' => true, 'sort_order' => 1]);
        RideCategory::query()->create([
            'slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'capacity_max' => 4,
            'flags' => ['cash_allowed' => true, 'open_destination_allowed' => true, 'multi_stop_allowed' => true],
        ]);
        foreach (['rides', 'category.economy', 'wallet', 'card', 'cash', 'open_destination', 'multi_stop'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        RideTaxRule::query()->create(['country_id' => $this->saudi->id, 'code' => 'vat', 'base' => 'commission', 'rate_bp' => 1500, 'borne_by' => 'driver', 'effective_from' => now()->subDay(), 'status' => true]);
        RideFeatures::forget();
        RidePolicies::forget();
        CityResolver::forget();
        // The seeder's Economy prices (config defaults are zero on purpose — an unpriced category is never offered).
        app(RidePolicies::class)->save($this->saudi->id, null, null, RideCategory::query()->where('slug', 'economy')->value('id'), [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
        ]);

        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_a_wallet_ride_from_booking_to_the_drivers_released_earnings(): void
    {
        $this->fund($this->rider, 10000);
        $driver = $this->onlineDriver();

        $quote = $this->quote()->json('data.0');
        $this->assertSame('upfront', $quote['pricing_mode']);
        $this->assertNotNull($quote['total_minor']);
        $this->assertContains('total', array_column($quote['lines'], 'code'));
        $this->assertNotContains('commission', array_column($quote['lines'], 'code'), 'the rider never sees the commission');
        $total = (int) $quote['total_minor'];

        $key = (string) Str::uuid();
        $book = $this->book($quote, 'wallet', $key)->assertCreated()->assertJsonPath('data.status', 'searching');
        $uuid = $book->json('data.uuid');
        $this->book($quote, 'wallet', $key)->assertCreated()->assertJsonPath('data.uuid', $uuid);
        $this->assertSame(1, Ride::query()->count(), 'the same Idempotency-Key never makes a second ride');
        $this->assertSame($total, $this->wallet($this->rider)->held_spend_only_minor + $this->wallet($this->rider)->held_withdrawable_minor);

        // The nearest driver got the request card.
        $offer = $this->asDriver($driver)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame($uuid, $offer['ride_uuid']);
        $this->assertGreaterThan(0, $offer['earning_minor']);

        $this->asDriver($driver)->postJson("/api/driver/v1/offers/{$offer['uuid']}/accept")->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.rider.name', 'Sara')
            ->assertJsonPath('data.navigation.lat', self::PICKUP['lat']);
        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/current')->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.driver.vehicle.plate_number', '1001');

        // Too far from the pickup to say "arrived".
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/arrive", ['lat' => 24.80, 'lng' => 46.80])
            ->assertStatus(422)->assertJsonPath('error_code', 'rides_not_at_pickup');

        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/arrive", ['lat' => 24.7137, 'lng' => 46.6754])->assertOk()->assertJsonPath('data.status', 'arrived');
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/start", ['lat' => 24.7137, 'lng' => 46.6754])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $end = $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/end", ['lat' => self::DROPOFF['lat'], 'lng' => self::DROPOFF['lng'], 'odometer_m' => 5200])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.payment_status', 'captured');

        // Upfront: the agreed price, nothing more (no paid waiting).
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame($total, $ride->final_total_minor);
        $this->assertSame($ride->final_total_minor, $ride->commission_minor + $ride->tax_minor + $ride->driver_net_minor, 'the tax is on the commission, carried by the driver');
        $this->assertSame((int) round($ride->commission_minor * 0.15), $ride->tax_minor);
        $this->assertSame($end->json('data.earning_minor'), $ride->driver_net_minor);
        $this->assertSame(10000 - $total, $this->wallet($this->rider)->spend_only_minor + $this->wallet($this->rider)->withdrawable_minor);
        $this->assertSame(0, $this->wallet($this->rider)->held_spend_only_minor + $this->wallet($this->rider)->held_withdrawable_minor);
        $this->assertSame(0, RideActiveLock::query()->count(), 'both locks are gone');
        $this->assertSame(PresenceStatus::Online, DriverPresence::query()->find($driver->id)->status);

        $this->assertTrue(FinancialEntry::query()->whereHas('category', fn ($q) => $q->where('slug', 'trip_taxes'))->where('amount_minor', $ride->tax_minor)->exists());
        $this->assertTrue(FinancialEntry::query()->whereHas('category', fn ($q) => $q->where('slug', 'trip_commissions'))->where('amount_minor', $ride->commission_minor)->exists());

        // The earning waits `settlement.pending_days`, then reaches the driver's wallet.
        $item = RideSettlementItem::query()->where('ride_id', $ride->id)->firstOrFail();
        $this->assertSame('pending', $item->status->value);
        $this->artisan('rides:release-earnings')->assertSuccessful();
        $this->assertSame('pending', $item->fresh()->status->value);
        $this->travel(3)->days();
        $this->artisan('rides:release-earnings')->assertSuccessful();
        $this->assertSame('posted', $item->fresh()->status->value);
        $this->assertSame($ride->driver_net_minor, $this->wallet($driver)->withdrawable_minor);

        $this->asDriver($driver)->getJson('/api/driver/v1/earnings')->assertOk()->assertJsonPath('data.profile.trips_count', 1);
    }

    public function test_a_cash_ride_charges_the_commission_to_the_driver_and_the_change_goes_to_the_rider(): void
    {
        $driver = $this->onlineDriver();
        $uuid = $this->book($this->quote()->json('data.0'), 'cash')->assertCreated()->json('data.uuid');
        $this->driveToTheEnd($driver, $uuid)->assertJsonPath('data.payment_status', 'cash_due');

        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $owed = $ride->commission_minor + $ride->tax_minor;
        $this->assertSame(-$owed, $this->wallet($driver)->withdrawable_minor, 'commission and its tax come off the driver');

        $this->asDriver($driver)->getJson('/api/driver/v1/rides/current')->assertOk()->assertJsonPath('data.cash_to_collect_minor', $ride->final_total_minor);
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/collect-cash", ['received_minor' => $ride->final_total_minor - 1])
            ->assertStatus(422)->assertJsonPath('error_code', 'rides_cash_below_total');
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/collect-cash", ['received_minor' => $ride->final_total_minor + 500])
            ->assertOk()->assertJsonPath('data.payment_status', 'cash_collected');

        $this->assertSame(500, $this->wallet($this->rider)->spend_only_minor, 'the change became wallet credit');
        $this->assertSame(-$owed - 500, $this->wallet($driver)->withdrawable_minor);
        $this->assertEmpty($this->asDriver($driver)->getJson('/api/driver/v1/rides/current')->assertOk()->json('data'), 'nothing left to do on that ride');
    }

    public function test_only_one_driver_gets_the_ride_and_a_busy_driver_gets_nothing_else(): void
    {
        // One captain per round (O-36's several-at-once is tested on its own).
        config(['rides.policy_defaults' => ['search.batch_size' => 1] + config('rides.policy_defaults')]);
        $first = $this->onlineDriver();
        $second = $this->onlineDriver(24.7200, 46.6800);
        $uuid = $this->book($this->quote()->json('data.0'), 'cash')->json('data.uuid');

        $offer = RideOffer::query()->sole();
        $this->assertSame($first->id, $offer->driver_id, 'the nearest driver first');
        $this->asDriver($second)->postJson("/api/driver/v1/offers/{$offer->uuid}/accept")->assertNotFound();

        // The first driver lets it time out: the next round goes to the second one.
        $this->travel(20)->seconds();
        $this->artisan('rides:dispatch-tick')->assertSuccessful();
        $next = RideOffer::query()->where('status', 'sent')->sole();
        $this->assertSame($second->id, $next->driver_id);
        $this->asDriver($first)->postJson("/api/driver/v1/offers/{$offer->uuid}/accept")->assertStatus(409)->assertJsonPath('error_code', 'rides_offer_expired');

        $this->asDriver($second)->postJson("/api/driver/v1/offers/{$next->uuid}/accept")->assertOk();
        $this->asDriver($second)->postJson("/api/driver/v1/offers/{$next->uuid}/accept")->assertOk()->assertJsonPath('data.uuid', $uuid);
        $this->assertSame($second->id, Ride::query()->where('uuid', $uuid)->value('driver_id'));

        // While on this ride the second driver is never offered another one.
        $other = User::query()->create(['name' => 'Omar', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->book($this->quote($other)->json('data.0'), 'cash')->assertCreated();
        $this->assertFalse(RideOffer::query()->where('driver_id', $second->id)->where('status', 'sent')->exists());
    }

    public function test_a_request_goes_to_several_captains_at_once_and_the_first_to_accept_takes_it(): void
    {
        // O-36 (owner): the request reaches `search.batch_size` captains together; the others' cards are withdrawn.
        $first = $this->onlineDriver();
        $second = $this->onlineDriver(24.7200, 46.6800);
        $uuid = $this->book($this->quote()->json('data.0'), 'cash')->json('data.uuid');

        $offers = RideOffer::query()->where('status', 'sent')->get();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $offers->pluck('driver_id')->all());

        $mine = $offers->firstWhere('driver_id', $second->id);
        $this->asDriver($second)->postJson("/api/driver/v1/offers/{$mine->uuid}/accept")->assertOk()->assertJsonPath('data.uuid', $uuid);
        $this->assertSame($second->id, Ride::query()->where('uuid', $uuid)->value('driver_id'));
        $this->assertFalse(RideOffer::query()->where('driver_id', $first->id)->where('status', 'sent')->exists(), 'the other card is withdrawn');
    }

    public function test_the_rider_has_one_active_ride_and_cancels_free_before_a_driver_accepts(): void
    {
        $this->fund($this->rider, 10000);
        $quote = $this->quote()->json('data.0');
        $uuid = $this->book($quote, 'wallet')->assertCreated()->json('data.uuid');

        $this->book($this->quote()->json('data.0'), 'cash')->assertStatus(409)->assertJsonPath('error_code', 'rides_ride_in_progress');

        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/$uuid/cancel/preview")->assertOk()
            ->assertJsonPath('data.stage', 'before_accept')->assertJsonPath('data.fee_minor', 0);
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/cancel")->assertOk()->assertJsonPath('data.status', 'canceled');

        $wallet = $this->wallet($this->rider);
        $this->assertSame(10000, $wallet->spend_only_minor + $wallet->withdrawable_minor);
        $this->assertSame(0, $wallet->held_spend_only_minor + $wallet->held_withdrawable_minor);
        $this->assertSame(0, RideActiveLock::query()->count());
    }

    public function test_a_late_cancel_after_the_driver_arrived_costs_the_fee_and_the_driver_gets_it(): void
    {
        RidePolicies::forget();
        app(RidePolicies::class)->save($this->saudi->id, null, null, null, ['price.cancel_fee_minor' => 1000]);
        $this->fund($this->rider, 10000);
        $driver = $this->onlineDriver();
        $uuid = $this->book($this->quote()->json('data.0'), 'wallet')->json('data.uuid');
        $this->acceptAndArrive($driver, $uuid);

        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/$uuid/cancel/preview")->assertOk()
            ->assertJsonPath('data.stage', 'after_arrival')->assertJsonPath('data.fee_minor', 1000);
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/cancel")->assertOk();

        $wallet = $this->wallet($this->rider);
        $this->assertSame(9000, $wallet->spend_only_minor + $wallet->withdrawable_minor);
        $this->assertSame(0, $wallet->held_spend_only_minor + $wallet->held_withdrawable_minor);
        $this->assertTrue(RideSettlementItem::query()->where('driver_id', $driver->id)->exists(), 'the driver who came is compensated');
        $this->assertSame(PresenceStatus::Online, DriverPresence::query()->find($driver->id)->status);
    }

    public function test_a_driver_who_steps_back_frees_the_ride_for_another_and_never_gets_it_again(): void
    {
        // One captain per round (O-36's several-at-once is tested on its own).
        config(['rides.policy_defaults' => ['search.batch_size' => 1] + config('rides.policy_defaults')]);
        $first = $this->onlineDriver();
        $second = $this->onlineDriver(24.7300, 46.6900);
        $uuid = $this->book($this->quote()->json('data.0'), 'cash')->json('data.uuid');
        $offer = RideOffer::query()->sole();
        $this->asDriver($first)->postJson("/api/driver/v1/offers/{$offer->uuid}/accept")->assertOk();

        $reason = \Modules\Rides\Models\RideCancelReason::query()->create(['actor' => 'driver', 'status' => true]);
        $this->asDriver($first)->postJson("/api/driver/v1/rides/$uuid/cancel", ['reason_id' => $reason->id])->assertOk();

        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame('searching', $ride->status->value);
        $this->assertNull($ride->driver_id);
        $this->assertSame($second->id, RideOffer::query()->where('status', 'sent')->sole()->driver_id);
        $this->assertSame(PresenceStatus::Online, DriverPresence::query()->find($first->id)->status);
    }

    public function test_no_show_only_after_the_wait(): void
    {
        $driver = $this->onlineDriver();
        $uuid = $this->book($this->quote()->json('data.0'), 'cash')->json('data.uuid');
        $this->acceptAndArrive($driver, $uuid);

        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/no-show")->assertStatus(409)->assertJsonPath('error_code', 'rides_no_show_too_early');
        $this->travel(301)->seconds();
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/no-show")->assertOk()->assertJsonPath('data.status', 'no_show');
        $this->assertSame(0, RideActiveLock::query()->count());
    }

    public function test_an_open_destination_ride_is_metered_by_the_distance_driven(): void
    {
        $this->freezeTime();
        $driver = $this->onlineDriver();
        $quote = $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::PICKUP])->assertOk()->json('data.0');
        $this->assertTrue($quote['open_destination']);
        $this->assertSame('metered', $quote['pricing_mode']);
        $this->assertNull($quote['total_minor']);

        $uuid = $this->book($quote, 'cash')->assertCreated()->json('data.uuid');
        $this->driveToTheEnd($driver, $uuid, 10000);

        // 10 km: base 600 + 10 × 180 + minutes × 30 (the ride took no time here) = 2400 SAR minor.
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(10000, $ride->metered_distance_m);
        $this->assertSame('device_odometer', $ride->distance_source->value);
        $this->assertSame(2400, $ride->final_total_minor);
        $this->assertSame(self::DROPOFF['lat'], $ride->destination_lat, 'the end point becomes the destination');
    }

    public function test_both_sides_rate_once_and_the_averages_follow(): void
    {
        $driver = $this->onlineDriver();
        $uuid = $this->book($this->quote()->json('data.0'), 'cash')->json('data.uuid');

        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/rate", ['stars' => 5])
            ->assertStatus(409)->assertJsonPath('error_code', 'rides_cannot_rate');
        $this->driveToTheEnd($driver, $uuid);

        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/rate", ['stars' => 5, 'comment' => 'Great'])->assertCreated();
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/rate", ['stars' => 4])->assertStatus(409)->assertJsonPath('error_code', 'rides_already_rated');
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/rate", ['stars' => 4])->assertCreated();

        $this->assertEquals(5.0, (float) DriverRideProfile::query()->find($driver->id)->rating_avg);
        $this->assertEquals(4.0, (float) \Modules\Rides\Models\RiderRideProfile::query()->find($this->rider->id)->rating_avg);
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/$uuid")->assertOk()->assertJsonPath('data.rated', true);
    }

    public function test_switched_off_payment_or_service_is_refused(): void
    {
        RideFeatureRule::query()->where('feature', 'cash')->update(['enabled' => false]);
        RideFeatures::forget();
        $this->book($this->quote()->json('data.0'), 'cash')->assertForbidden()->assertJsonPath('error_code', 'rides_feature_disabled');

        RideFeatureRule::query()->where('feature', 'rides')->update(['enabled' => false]);
        RideFeatures::forget();
        $this->quote()->assertForbidden()->assertJsonPath('error_code', 'rides_feature_disabled');
    }

    public function test_a_card_ride_is_paid_on_the_shared_payment_screen_then_held_and_searched(): void
    {
        $this->fund($this->rider, 10000);
        app(PinService::class)->set($this->rider, '1234');
        $driver = $this->onlineDriver();
        $quote = $this->quote()->json('data.0');

        $ride = $this->book($quote, 'card')->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.payment_status', 'awaiting_topup')
            ->json('data');
        $this->assertNotNull($ride['checkout_id']);
        $this->assertSame(0, RideOffer::query()->count(), 'nobody is offered an unpaid ride');

        // The app's one payment screen: the ride's amount, the wallet against it.
        $this->getJson("/api/mobile/v1/wallet/checkouts/{$ride['checkout_id']}", ['X-Country' => 'SA'])->assertOk()
            ->assertJsonPath('data.purpose', 'ride')
            ->assertJsonPath('data.amount_minor', $quote['hold_minor'])
            ->assertJsonPath('data.wallet.enough', true);
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$ride['checkout_id']}/coupon", ['code' => 'ANY'], ['X-Country' => 'SA'])->assertStatus(422);

        $this->postJson("/api/mobile/v1/wallet/checkouts/{$ride['checkout_id']}/pay", ['method' => 'wallet'], ['X-Country' => 'SA', 'X-Wallet-Pin' => '1234'])
            ->assertOk()->assertJsonPath('data.status', 'paid');

        // Held, not taken: the search starts and the driver gets the card.
        $wallet = $this->wallet($this->rider);
        $this->assertSame(10000, $wallet->spend_only_minor + $wallet->withdrawable_minor);
        $this->assertSame($quote['hold_minor'], $wallet->held_spend_only_minor + $wallet->held_withdrawable_minor);
        $this->assertSame('searching', Ride::query()->where('uuid', $ride['uuid'])->value('status')->value);
        $this->assertSame($driver->id, RideOffer::query()->sole()->driver_id);

        $this->driveToTheEnd($driver, $ride['uuid'])->assertJsonPath('data.payment_status', 'captured');
        $wallet = $this->wallet($this->rider);
        $this->assertSame(10000 - $quote['total_minor'], $wallet->spend_only_minor + $wallet->withdrawable_minor);
        $this->assertSame(0, $wallet->held_spend_only_minor + $wallet->held_withdrawable_minor);
    }

    public function test_a_card_ride_paid_through_a_gateway_starts_the_moment_the_gateway_confirms(): void
    {
        $this->seed(FinancialCategorySeeder::class);
        app(PinService::class)->set($this->rider, '1234');
        $this->onlineDriver();
        $method = PaymentMethod::create(['code' => 'mf', 'gateway' => 'myfatoorah', 'type' => PaymentMethodType::Online, 'is_global' => true, 'supports_topup' => true, 'status' => true, 'credentials' => ['api_url' => 'https://mf.test', 'api_key' => 'KEY']]);
        $quote = $this->quote()->json('data.0');
        $amount = number_format($quote['hold_minor'] / 100, 2, '.', '');
        $this->http = [
            'mf.test/v2/SendPayment' => Http::response(['IsSuccess' => true, 'Data' => ['InvoiceURL' => 'https://pay.test/inv/1', 'InvoiceId' => 555]]),
            'mf.test/v2/GetPaymentStatus' => Http::response(['IsSuccess' => true, 'Data' => ['InvoiceId' => 555, 'InvoiceValue' => (float) $amount, 'InvoiceTransactions' => [['TransactionStatus' => 'Succss']]]]),
        ];

        $ride = $this->book($quote, 'card')->assertCreated()->json('data');
        $payment = $this->postJson("/api/mobile/v1/wallet/checkouts/{$ride['checkout_id']}/pay", ['method' => 'gateway', 'payment_method_id' => $method->id], ['X-Country' => 'SA', 'X-Wallet-Pin' => '1234', 'Idempotency-Key' => 'pay-ride-0001'])
            ->assertOk()->assertJsonPath('data.payment.redirect_url', 'https://pay.test/inv/1')->json('data.payment.uuid');
        $this->assertSame('awaiting_payment', Ride::query()->where('uuid', $ride['uuid'])->value('status')->value);

        // The gateway calls back (the app may be closed): the top-up lands, is held, and the search starts.
        $this->get("/api/wallet/payments/{$payment}/callback?paymentId=PAY-1");

        $this->assertSame('searching', Ride::query()->where('uuid', $ride['uuid'])->value('status')->value);
        $this->assertSame('paid', Checkout::query()->where('uuid', $ride['checkout_id'])->value('status')->value);
        $wallet = $this->wallet($this->rider);
        $this->assertSame($quote['hold_minor'], $wallet->held_spend_only_minor + $wallet->held_withdrawable_minor);
        $this->assertSame(1, RideOffer::query()->count());
    }

    public function test_an_unpaid_card_ride_expires_and_its_payment_screen_closes(): void
    {
        $ride = $this->book($this->quote()->json('data.0'), 'card')->assertCreated()->json('data');

        $this->travel(16)->minutes();
        $this->artisan('rides:dispatch-tick')->assertSuccessful();

        $this->assertSame('expired', Ride::query()->where('uuid', $ride['uuid'])->value('status')->value);
        $this->assertSame('expired', Checkout::query()->where('uuid', $ride['checkout_id'])->value('status')->value);
        $this->assertSame(0, RideActiveLock::query()->count(), 'the rider can book again');
    }

    public function test_documents_can_be_required_from_residents_only_or_citizens_only(): void
    {
        $citizen = $this->onlineDriver();
        $resident = $this->onlineDriver(24.7160, 46.6770);
        $resident->update(['residency' => 'resident']);
        $permit = \Modules\Driver\Models\DriverDocumentType::query()->create(['code' => 'work_permit', 'applies_to' => 'driver', 'has_expiry' => true, 'status' => true]);
        \Modules\Driver\Models\DriverDocumentRequirement::query()->create(['document_type_id' => $permit->id, 'country_id' => $this->saudi->id, 'residency' => 'resident', 'is_required' => true]);
        $gate = app(\Modules\Rides\Services\Drivers\DriverEligibility::class);

        $this->assertNull($gate->reasonNotToWork($citizen->fresh()), 'a residents-only document never blocks a citizen');
        $this->assertSame('documents_missing', $gate->reasonNotToWork($resident->fresh()));

        \Modules\Driver\Models\DriverDocument::query()->create(['driver_id' => $resident->id, 'document_type_id' => $permit->id, 'status' => 'approved', 'expires_at' => now()->addYear()]);
        $this->assertNull($gate->reasonNotToWork($resident->fresh()));

        // The admin switches the document type off: nobody needs it any more.
        $permit->update(['status' => false]);
        \Modules\Driver\Models\DriverDocument::query()->delete();
        $this->assertNull($gate->reasonNotToWork($resident->fresh()));
    }

    public function test_the_dispatch_check_says_why_a_request_did_not_reach_a_captain(): void
    {
        // O-47: a captain with the rider's own phone number is never sent that rider's request.
        $same = $this->onlineDriver();
        $same->forceFill(['phone' => $this->rider->phone])->save();
        $other = $this->onlineDriver(24.7200, 46.6800);
        $uuid = $this->book($this->quote()->json('data.0'), 'cash')->json('data.uuid');
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();

        $admin = \Modules\Admin\Models\Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        \Spatie\Permission\Models\Permission::findOrCreate('rides.view', 'admin_api');
        $admin->givePermissionTo('rides.view');
        \Laravel\Sanctum\Sanctum::actingAs($admin, [], 'admin_api');
        $captains = collect($this->getJson("/api/admin/v1/rides/{$ride->id}/dispatch-check")->assertOk()->json('data.captains'))->keyBy('driver_id');
        $this->assertSame('same_phone_as_rider', $captains[$same->id]['reason']);
        $this->assertSame('offered_sent', $captains[$other->id]['reason']);

        // The captain's own readiness list.
        $checks = collect($this->asDriver($other)->getJson('/api/driver/v1/dispatch-check')->assertOk()->json('data.checks'))->keyBy('key');
        $this->assertTrue($checks['online']['ok']);
        $this->assertTrue($checks['account']['ok']);
        $this->assertArrayHasKey('server_dispatch_tick', $checks->all());
    }

    public function test_an_in_app_call_rings_the_other_side_is_answered_with_a_livekit_token_and_ends(): void
    {
        // O-09 (X-13: LiveKit): neither side sees the other's number — the call goes through DORR.
        config(['chat.livekit.url' => 'wss://lk.test', 'chat.livekit.api_key' => 'key', 'chat.livekit.api_secret' => str_repeat('s', 32)]);
        $driver = $this->onlineDriver();
        $uuid = $this->book($this->quote()->json('data.0'), 'cash')->json('data.uuid');
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/calls")->assertStatus(409)->assertJsonPath('error_code', 'rides_call_not_now');

        $this->acceptAndArrive($driver, $uuid);
        $call = $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/calls")->assertCreated()
            ->assertJsonPath('data.status', 'ringing')->assertJsonPath('data.join.url', 'wss://lk.test')->json('data');
        $this->assertNotEmpty($call['join']['token']);

        // The rider sees it ringing on the ride, answers, and gets their own token for the same room.
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/$uuid")->assertJsonPath('data.call.uuid', $call['uuid'])->assertJsonPath('data.call.caller', 'driver');
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/calls/{$call['uuid']}/accept")->assertOk()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.join.room', $call['join']['room']);
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/calls/{$call['uuid']}/accept")->assertStatus(409);

        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/calls/{$call['uuid']}/end")->assertOk()->assertJsonPath('data.status', 'ended');
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/$uuid")->assertJsonPath('data.call', null);
    }

    public function test_the_ride_chat_is_open_only_while_a_driver_is_on_the_ride_and_is_kept_after(): void
    {
        $driver = $this->onlineDriver();
        $uuid = $this->book($this->quote()->json('data.0'), 'cash')->json('data.uuid');

        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/messages", ['body' => 'hello'])
            ->assertStatus(409)->assertJsonPath('error_code', 'rides_chat_closed');

        $offer = RideOffer::query()->sole();
        $this->asDriver($driver)->postJson("/api/driver/v1/offers/{$offer->uuid}/accept")->assertOk();

        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/messages", ['body' => 'I am at gate 2', 'client_id' => 'c-1'])
            ->assertCreated()->assertJsonPath('data.mine', true);
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/messages", ['body' => 'I am at gate 2', 'client_id' => 'c-1'])->assertCreated();
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/messages", ['quick' => 'on_my_way'])->assertCreated()->assertJsonPath('data.kind', 'quick');
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/messages", ['quick' => 'coming'])->assertUnprocessable();

        $this->asDriver($driver)->getJson("/api/driver/v1/rides/$uuid/messages")->assertOk()
            ->assertJsonPath('data.open', true)
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.body', 'I am at gate 2')
            ->assertJsonPath('data.messages.0.mine', false)
            ->assertJsonPath('data.quick_replies.0.key', 'on_my_way');
        $this->assertNotNull(\Modules\Rides\Models\RideMessage::query()->where('sender_type', 'user')->value('read_at'), 'read once the driver opened the chat');

        // The ride ends: nobody can write any more, the conversation stays.
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/arrive", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']]);
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/start", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']]);
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/end", ['lat' => self::DROPOFF['lat'], 'lng' => self::DROPOFF['lng']])->assertOk();
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/messages", ['body' => 'thanks'])->assertStatus(409);
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/$uuid/messages")->assertOk()
            ->assertJsonPath('data.open', false)->assertJsonCount(2, 'data.messages');
    }

    public function test_a_ride_ended_away_from_the_destination_is_refused_unless_ended_early_and_then_repriced(): void
    {
        $this->fund($this->rider, 10000);
        $driver = $this->onlineDriver();
        $quote = $this->quote()->json('data.0');
        $total = (int) $quote['total_minor'];
        $this->assertGreaterThan(1500, $total, 'the trip is worth more than the minimum fare');
        $uuid = $this->book($quote, 'wallet')->assertCreated()->json('data.uuid');

        $this->acceptAndArrive($driver, $uuid);
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/start", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk();

        // O-39: "end ride" without having moved — the destination is kilometres away.
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/end", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])
            ->assertStatus(422)->assertJsonPath('error_code', 'rides_not_at_destination');
        $this->assertSame('in_progress', Ride::query()->where('uuid', $uuid)->value('status')->value);

        // O-40: confirmed as an early end, the fare is what was actually driven — not the whole agreed trip,
        // and not the planned route either when the phone logged no points.
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/end", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng'], 'early' => true])
            ->assertOk()->assertJsonPath('data.status', 'completed');
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(1500, $ride->final_total_minor, 'the minimum fare, not the '.$total.' agreed for the whole trip');
        $this->assertSame(10000 - 1500, $this->wallet($this->rider)->spend_only_minor + $this->wallet($this->rider)->withdrawable_minor);
        $this->assertTrue(\Modules\Rides\Models\RideEvent::query()->where('ride_id', $ride->id)->where('type', 'completed')->where('payload->ended_early', true)->exists(), 'kept for review');
    }

    public function test_not_enough_in_the_wallet_says_which_wallet_and_what_is_free_and_held(): void
    {
        $this->fund($this->rider, 1000);
        $this->wallet($this->rider)->forceFill(['held_withdrawable_minor' => 300])->save();
        $quote = $this->quote()->json('data.0');

        // O-38e: the rider sees the ride's own wallet (one per country), its free balance and what is held.
        $this->book($quote, 'wallet')->assertUnprocessable()
            ->assertJsonPath('error_code', 'rides_insufficient_balance')
            ->assertJsonPath('data.available_minor', 700)
            ->assertJsonPath('data.held_minor', 300)
            ->assertJsonPath('data.wallet_country', 'SA')
            ->assertJsonPath('data.shortfall_minor', (int) $quote['total_minor'] - 700);
    }

    public function test_every_charge_takes_the_non_withdrawable_money_first(): void
    {
        $wallets = app(WalletService::class);
        $wallet = $wallets->firstOrCreateWallet($this->rider, $this->saudi);
        $wallets->credit($wallet, 300, WalletBucket::SpendOnly, WalletTransactionType::Topup, ['idempotency_key' => 'bonus']);
        $wallets->credit($wallet, 1000, WalletBucket::Withdrawable, WalletTransactionType::Topup, ['idempotency_key' => 'cash-in']);

        // O-38f: 500 = all 300 non-withdrawable + 200 withdrawable; a retry moves nothing more.
        $wallets->debitSpendOnlyFirst($wallet, 500, WalletTransactionType::Penalty, ['idempotency_key' => 'fine-1']);
        $wallets->debitSpendOnlyFirst($wallet, 500, WalletTransactionType::Penalty, ['idempotency_key' => 'fine-1']);
        $this->assertSame([0, 800], [$this->wallet($this->rider)->spend_only_minor, $this->wallet($this->rider)->withdrawable_minor]);

        // Past what is there, the rest is a withdrawable debt (spend-only never goes below zero).
        $wallets->debitSpendOnlyFirst($wallet, 1000, WalletTransactionType::Penalty, ['idempotency_key' => 'fine-2']);
        $this->assertSame([0, -200], [$this->wallet($this->rider)->spend_only_minor, $this->wallet($this->rider)->withdrawable_minor]);
    }

    public function test_with_me_a_second_rider_on_the_way_joins_the_car_and_the_fares_are_split(): void
    {
        // O-30..O-32: a With Me category, priced like Economy, the money split equally (the admin's option).
        $withMe = RideCategory::query()->create(['slug' => 'with_me', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 2, 'capacity_max' => 1, 'flags' => ['cash_allowed' => true]]);
        RideFeatureRule::query()->create(['feature' => 'category.with_me', 'country_id' => $this->saudi->id, 'enabled' => true]);
        RideFeatures::forget();
        app(RidePolicies::class)->save($this->saudi->id, null, null, $withMe->id, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
            'with_me.split' => 'equal', 'with_me.driver_share_bp' => 2000,
        ]);
        $driver = $this->onlineDriver();

        $pick = fn (User $who) => collect($this->quote($who)->json('data'))->firstWhere('category.slug', 'with_me');
        $first = $this->book($pick($this->rider), 'cash')->assertCreated()->json('data.uuid');
        $this->acceptAndArrive($driver, $first);
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$first/start", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk();
        $fare = (int) Ride::query()->where('uuid', $first)->value('confirmed_snapshot')['breakdown']['fare'];

        // Another rider, the same way: the request comes to the captain already carrying the first one.
        $second = User::query()->create(['name' => 'Omar Ali', 'phone' => '+966500000077', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $uuid2 = $this->book($pick($second), 'cash')->assertCreated()->json('data.uuid');
        $offer = RideOffer::query()->where('ride_id', Ride::query()->where('uuid', $uuid2)->value('id'))->where('driver_id', $driver->id)->firstOrFail();
        $this->asDriver($driver)->postJson("/api/driver/v1/offers/{$offer->uuid}/accept")->assertOk();

        // 20% of the newcomer's price is the captain's; the rest comes off both fares, equally.
        $each = intdiv((int) round($fare * 0.8), 2);
        $one = Ride::query()->where('uuid', $first)->firstOrFail();
        $two = Ride::query()->where('uuid', $uuid2)->firstOrFail();
        $this->assertSame($one->pool_id, $two->pool_id);
        $this->assertSame($each, (int) $one->pool_discount_minor);
        $this->assertSame($each, (int) $two->pool_discount_minor);

        // At the end the first rider pays the fare less what sharing took off.
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$first/end", ['lat' => self::DROPOFF['lat'], 'lng' => self::DROPOFF['lng']])->assertOk();
        $this->assertSame($fare - $each, (int) Ride::query()->where('uuid', $first)->value('final_total_minor'));
    }

    public function test_an_early_end_never_costs_more_than_the_agreed_price(): void
    {
        $driver = $this->onlineDriver();
        $quote = $this->quote()->json('data.0');
        $uuid = $this->book($quote, 'cash')->assertCreated()->json('data.uuid');
        $this->acceptAndArrive($driver, $uuid);
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/start", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk();

        // A long detour reported by the phone, ending 1.5 km short of the destination.
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/end", ['lat' => 24.7380, 'lng' => 46.6900, 'odometer_m' => 40000, 'early' => true])->assertOk();
        $this->assertSame((int) $quote['total_minor'], Ride::query()->where('uuid', $uuid)->value('final_total_minor'));
    }

    // ---------------------------------------------------------------- helpers

    private function quote(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => self::PICKUP, 'destination' => self::DROPOFF]);
    }

    /** @param array<string, mixed> $quote */
    private function book(array $quote, string $method, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/mobile/v1/rides', ['quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => $method], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function acceptAndArrive(Driver $driver, string $uuid): void
    {
        $offer = RideOffer::query()->where('driver_id', $driver->id)->where('status', 'sent')->sole();
        $this->asDriver($driver)->postJson("/api/driver/v1/offers/{$offer->uuid}/accept")->assertOk();
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/arrive", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk();
    }

    private function driveToTheEnd(Driver $driver, string $uuid, ?int $odometer = null): TestResponse
    {
        $this->acceptAndArrive($driver, $uuid);
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/start", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk();

        return $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/end", ['lat' => self::DROPOFF['lat'], 'lng' => self::DROPOFF['lng'], 'odometer_m' => $odometer])
            ->assertOk()->assertJsonPath('data.status', 'completed');
    }

    private function onlineDriver(float $lat = 24.7150, float $lng = 46.6760): Driver
    {
        $n = ++$this->plates;
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

    private function asDriver(Driver $driver): static
    {
        return $this->actingAs($driver, 'driver_api');
    }

    private function fund(User $user, int $minor): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($user, $this->saudi), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup, ['idempotency_key' => 'fund:'.$user->id]);
    }

    private function wallet(User|Driver $owner): Wallet
    {
        return app(WalletService::class)->firstOrCreateWallet($owner, $this->saudi)->fresh();
    }
}
