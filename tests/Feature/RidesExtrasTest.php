<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\LegalPage;
use App\Models\ServiceCategory;
use Illuminate\Support\Facades\DB;
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
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Models\RidePriceLine;
use Modules\Rides\Models\RideSettlementItem;
use Modules\Rides\Models\RideTaxRule;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\FinancialEntry;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletCoupon;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * Batch 2 of docs/rides_tasks.md: the tip after the ride (R-144, D-182, D-204), the coupon on the
 * quote (R-45), DORR's service fee (R-47) and payment-method fee (R-48), and the cancellation /
 * refund summary read before confirming (R-52, R-53).
 */
class RidesExtrasTest extends TestCase
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
            'flags' => ['cash_allowed' => true, 'open_destination_allowed' => true],
        ])->id;
        foreach (['rides', 'category.economy', 'wallet', 'card', 'cash', 'open_destination', 'tips', 'coupons'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        RideTaxRule::query()->create(['country_id' => $this->saudi->id, 'code' => 'vat', 'base' => 'commission', 'rate_bp' => 1500, 'borne_by' => 'driver', 'effective_from' => now()->subDay(), 'status' => true]);
        RideFeatures::forget();
        RidePolicies::forget();
        CityResolver::forget();
        $this->price([]);

        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_the_quote_shows_the_customer_fee_and_the_coupon_and_dorr_pays_the_discount(): void
    {
        $this->price(['price.customer_fee_minor' => 200]);
        $plain = $this->quote()->assertOk()->json('data.0');
        $coupon = $this->coupon(['kind' => 'fixed', 'value' => 500]);

        $quote = $this->quote(['coupon_code' => strtolower($coupon->code)])->assertOk()->json('data.0');
        $lines = collect($quote['lines'])->keyBy('code');
        $this->assertSame(200, $lines['customer_fee']['amount_minor']);
        $this->assertSame(-500, $lines['discount']['amount_minor']);
        $this->assertSame($plain['total_minor'] - 500, $quote['total_minor'], 'the coupon comes off the total');
        $this->assertSame(['code' => $coupon->code, 'discount_minor' => 500, 'applied' => true, 'reason' => null], $quote['coupon']);
        $this->assertSame(['wallet' => 0, 'card' => 0, 'cash' => 0], $quote['payment_fees']);

        $this->fund($this->rider, 10000);
        $driver = $this->onlineDriver();
        $uuid = $this->book($quote, 'wallet')->assertCreated()->json('data.uuid');
        $this->assertSame(1, $coupon->fresh()->used_count, 'spent at confirmation');
        $this->assertSame('used', $coupon->fresh()->status);

        $this->driveToTheEnd($driver, $uuid);
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame($quote['total_minor'], $ride->final_total_minor);
        $fare = (int) RidePriceLine::query()->where('ride_id', $ride->id)->where('phase', 'final')->where('code', 'fare')->value('amount_minor');
        $this->assertSame($fare - $ride->commission_minor - $ride->tax_minor, $ride->driver_net_minor, 'the driver nets on the whole fare — the coupon is DORR\'s');
        $this->assertSame(10000 - $quote['total_minor'], $this->wallet($this->rider)->withdrawable_minor);

        $this->assertTrue($this->ledger('discount_expenses', 500));
        $this->assertTrue($this->ledger('trip_customer_fees', 200));
        $this->assertSame(500, (int) DB::table('wallet_coupon_redemptions')->where('ride_id', $ride->id)->value('discount_minor'));

        // The same coupon can't be used twice.
        $this->quote(['coupon_code' => $coupon->code])->assertStatus(422)->assertJsonPath('error_code', 'rides_coupon_used');
    }

    public function test_a_cash_ride_settles_the_fees_and_the_coupon_on_the_drivers_wallet(): void
    {
        $this->price(['price.customer_fee_minor' => 200, 'payment.cash_fee_minor' => 100]);
        $coupon = $this->coupon(['kind' => 'percent', 'value' => 10, 'max_discount_minor' => 300]);
        $quote = $this->quote(['coupon_code' => $coupon->code])->json('data.0');
        $this->assertSame(100, $quote['payment_fees']['cash']);
        $discount = $quote['coupon']['discount_minor'];
        $this->assertGreaterThan(0, $discount);
        $this->assertLessThanOrEqual(300, $discount);

        $driver = $this->onlineDriver();
        $book = $this->book($quote, 'cash')->assertCreated();
        $this->assertSame($quote['total_minor'] + 100, $book->json('data.agreed_price_minor'), 'the cash fee joins at confirmation');
        $this->assertContains('payment_method_fee', array_column($book->json('data.lines'), 'code'));

        $uuid = $book->json('data.uuid');
        $this->driveToTheEnd($driver, $uuid);
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame($quote['total_minor'] + 100, $ride->final_total_minor);

        // The driver kept the cash: DORR's commission, tax and fees come off; the coupon's part comes back.
        $this->assertSame(-$ride->commission_minor - $ride->tax_minor - 200 - 100 + $discount, $this->wallet($driver)->withdrawable_minor);
        $this->assertSame($ride->driver_net_minor, $ride->final_total_minor + $this->wallet($driver)->withdrawable_minor, 'cash in hand + wallet = the driver\'s net');
        $this->assertTrue(RideSettlementItem::query()->where('ride_id', $ride->id)->where('type', 'discount_compensation')->where('amount_minor', $discount)->exists());
        $this->assertTrue($this->ledger('trip_payment_fees', 100));
    }

    public function test_the_card_fee_is_added_to_the_held_amount(): void
    {
        $this->price(['payment.card_fee_minor' => 100, 'payment.card_fee_bp' => 200]);
        $quote = $this->quote()->json('data.0');
        $fee = 100 + intdiv($quote['total_minor'] * 200 + 5000, 10000);
        $this->assertSame($fee, $quote['payment_fees']['card']);

        $book = $this->book($quote, 'card')->assertCreated()->assertJsonPath('data.status', 'awaiting_payment');
        $ride = Ride::query()->where('uuid', $book->json('data.uuid'))->firstOrFail();
        $this->assertSame($quote['total_minor'] + $fee, $ride->agreed_price_minor);
        $this->assertSame($quote['total_minor'] + $fee, (int) $ride->snapshot('hold_minor'));
    }

    public function test_a_cancelled_ride_gives_the_coupon_back_and_bad_codes_are_refused(): void
    {
        $coupon = $this->coupon(['kind' => 'fixed', 'value' => 300]);
        $this->fund($this->rider, 10000);
        $uuid = $this->book($this->quote(['coupon_code' => $coupon->code])->json('data.0'), 'wallet')->assertCreated()->json('data.uuid');
        $this->assertSame('used', $coupon->fresh()->status);

        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/cancel")->assertOk();
        $this->assertSame('active', $coupon->fresh()->status);
        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertSame(0, DB::table('wallet_coupon_redemptions')->count());

        $this->quote(['coupon_code' => 'NOPE-1234'])->assertStatus(422)->assertJsonPath('error_code', 'rides_coupon_invalid');
        $sports = $this->coupon(['kind' => 'fixed', 'value' => 300, 'purposes' => ['sports_contest']]);
        $this->quote(['coupon_code' => $sports->code])->assertStatus(422)->assertJsonPath('error_code', 'rides_coupon_not_here');
        $expired = $this->coupon(['kind' => 'fixed', 'value' => 300, 'expires_at' => now()->subDay()]);
        $this->quote(['coupon_code' => $expired->code])->assertStatus(422)->assertJsonPath('error_code', 'rides_coupon_expired');
        $big = $this->coupon(['kind' => 'fixed', 'value' => 300, 'min_amount_minor' => 999999]);
        $this->quote(['coupon_code' => $big->code])->assertOk()->assertJsonPath('data.0.coupon.applied', false)->assertJsonPath('data.0.coupon.discount_minor', 0);

        RideFeatureRule::query()->where('feature', 'coupons')->update(['enabled' => false]);
        RideFeatures::forget();
        $this->quote(['coupon_code' => $coupon->code])->assertStatus(403)->assertJsonPath('error_code', 'rides_feature_disabled');
    }

    public function test_the_rider_reads_the_cancellation_and_refund_rules_before_confirming(): void
    {
        $this->price(['price.cancel_fee_minor' => 1000, 'cancel.free_after_accept_s' => 120, 'no_show.wait_s' => 300]);
        $summary = $this->quote()->json('data.0.policy_summary');

        $this->assertSame(1000, $summary['cancel']['fee_minor']);
        $this->assertSame(120, $summary['cancel']['free_after_accept_s']);
        $this->assertSame(300, $summary['no_show']['wait_s']);
        $this->assertSame(['cancel_before_accept', 'cancel_after_accept', 'no_show'], array_column($summary['items'], 'code'));
        $this->assertStringContainsString('10.00', $summary['items'][1]['text']);
        $this->assertNotSame($summary['refund']['wallet'], $summary['refund']['cash']);
    }

    public function test_the_quote_names_the_terms_in_force_before_booking(): void
    {
        $this->assertSame([], $this->quote()->json('data.0.terms'), 'no published page, nothing to show');

        $rides = ServiceCategory::create(['module_name' => 'passenger_ride', 'status' => true, 'sort_order' => 1]);
        LegalPage::create(['type' => 'term', 'service_id' => null, 'status' => true]);
        LegalPage::create(['type' => 'privacy', 'service_id' => null, 'status' => true]);
        $terms = collect($this->quote()->json('data.0.terms'))->keyBy('type');
        $this->assertSame(['term', 'privacy'], $terms->keys()->all());
        $this->assertSame('general', $terms['term']['scope']);
        $this->assertNull($terms['term']['service_id']);

        // The rides service's own terms win over the general ones.
        LegalPage::create(['type' => 'term', 'service_id' => $rides->id, 'status' => true]);
        $terms = collect($this->quote()->json('data.0.terms'))->keyBy('type');
        $this->assertSame('service', $terms['term']['scope']);
        $this->assertSame($rides->id, $terms['term']['service_id']);
        $this->assertSame('general', $terms['privacy']['scope']);
        $this->assertNotNull($terms['term']['updated_at']);
    }

    public function test_a_tip_goes_whole_to_the_driver_once_within_the_window(): void
    {
        $this->fund($this->rider, 10000);
        $driver = $this->onlineDriver();
        $uuid = $this->book($this->quote()->json('data.0'), 'wallet')->json('data.uuid');

        // Not before the end.
        $this->tip($uuid, 500)->assertStatus(409)->assertJsonPath('error_code', 'rides_cannot_tip');
        $this->driveToTheEnd($driver, $uuid);
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();

        $offer = $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/$uuid")->assertOk()->json('data.tip');
        $this->assertTrue($offer['allowed']);
        $this->assertSame([500, 1000, 2000], $offer['presets_minor']);
        $this->assertSame(10000, $offer['max_minor']);

        $this->tip($uuid, 20000)->assertStatus(422)->assertJsonPath('error_code', 'rides_tip_out_of_range');
        $before = $this->wallet($this->rider)->withdrawable_minor;
        $key = (string) Str::uuid();
        $this->tip($uuid, 1000, $key)->assertOk()->assertJsonPath('data.tip.amount_minor', 1000)->assertJsonPath('data.tip.allowed', false);
        $this->tip($uuid, 1000, $key)->assertOk();
        $this->tip($uuid, 500)->assertStatus(409)->assertJsonPath('error_code', 'rides_already_tipped');

        $this->assertSame($before - 1000, $this->wallet($this->rider)->withdrawable_minor, 'charged once');
        $this->assertSame(1000, $this->wallet($driver)->withdrawable_minor, 'all of it to the driver, now — no commission');
        $this->assertTrue(RideSettlementItem::query()->where('ride_id', $ride->id)->where('type', 'tip')->where('status', 'posted')->where('amount_minor', 1000)->exists());
        $this->asDriver($driver)->getJson("/api/driver/v1/rides/$uuid")->assertOk()->assertJsonPath('data.tip_minor', 1000);
        $this->asDriver($driver)->getJson('/api/driver/v1/earnings')->assertOk()->assertJsonPath('data.today.tips_minor', 1000);
        $this->asDriver($driver)->getJson('/api/driver/v1/rides')->assertOk()->assertJsonPath('data.items.0.tip_minor', 1000);
    }

    public function test_a_tip_needs_the_switch_the_balance_and_the_window(): void
    {
        $this->fund($this->rider, 5000);
        $driver = $this->onlineDriver();
        $quote = $this->quote()->json('data.0');
        $uuid = $this->book($quote, 'cash')->json('data.uuid');
        $this->driveToTheEnd($driver, $uuid);

        // A cash ride can still be tipped — from the wallet.
        $this->tip($uuid, 6000)->assertStatus(422)->assertJsonPath('error_code', 'rides_insufficient_balance')->assertJsonPath('data.shortfall_minor', 1000);

        RideFeatureRule::query()->where('feature', 'tips')->update(['enabled' => false]);
        RideFeatures::forget();
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/$uuid")->assertOk()->assertJsonPath('data.tip', null);
        $this->tip($uuid, 500)->assertStatus(403)->assertJsonPath('error_code', 'rides_feature_disabled');

        RideFeatureRule::query()->where('feature', 'tips')->update(['enabled' => true]);
        RideFeatures::forget();
        $this->travel(25)->hours();
        $this->tip($uuid, 500)->assertStatus(409)->assertJsonPath('error_code', 'rides_tip_window_closed');
    }

    /** @param array<string, mixed> $values */
    private function price(array $values): void
    {
        app(RidePolicies::class)->save($this->saudi->id, null, null, $this->economyId, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
        ] + $values);
        RidePolicies::forget();
    }

    /** @param array<string, mixed> $options */
    private function coupon(array $options): WalletCoupon
    {
        return WalletCoupon::query()->create($options + [
            'code' => 'DORR-'.strtoupper(Str::random(8)), 'country_id' => $this->saudi->id, 'usage_limit' => 1, 'used_count' => 0,
            'source' => 'admin', 'status' => 'active', 'starts_at' => now()->subMinute(),
        ]);
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

    private function tip(string $uuid, int $amount, ?string $key = null): TestResponse
    {
        return $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/$uuid/tip", ['amount_minor' => $amount], $key === null ? [] : ['Idempotency-Key' => $key]);
    }

    private function driveToTheEnd(Driver $driver, string $uuid): void
    {
        $offer = RideOffer::query()->where('driver_id', $driver->id)->where('status', 'sent')->sole();
        $this->asDriver($driver)->postJson("/api/driver/v1/offers/{$offer->uuid}/accept")->assertOk();
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/arrive", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk();
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/start", ['lat' => self::PICKUP['lat'], 'lng' => self::PICKUP['lng']])->assertOk();
        $this->asDriver($driver)->postJson("/api/driver/v1/rides/$uuid/end", ['lat' => self::DROPOFF['lat'], 'lng' => self::DROPOFF['lng']])
            ->assertOk()->assertJsonPath('data.status', 'completed');
    }

    private function onlineDriver(): Driver
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
            'driver_id' => $driver->id, 'status' => PresenceStatus::Online, 'lat' => 24.7150, 'lng' => 46.6760, 'gps_enabled' => true,
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

    private function ledger(string $slug, int $amount): bool
    {
        return FinancialEntry::query()->whereHas('category', fn ($q) => $q->where('slug', $slug))->where('amount_minor', $amount)->exists();
    }
}
