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
use Modules\Rides\Models\LoyaltyBadge;
use Modules\Rides\Models\LoyaltyChallenge;
use Modules\Rides\Models\LoyaltyReward;
use Modules\Rides\Models\LoyaltyTier;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\WalletCoupon;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * Trip add-ons (R-233..R-237, R-NEW-06) and DORR points (R-146..R-155) through a whole ride.
 */
class RidesLoyaltyAndAddonsTest extends TestCase
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
        foreach (['rides', 'category.economy', 'wallet', 'cash', 'wait_save', 'priority_pickup', 'child_seat', 'pet', 'service_animal', 'luggage', 'loyalty'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        RideFeatures::forget();
        CityResolver::forget();
        app(RidePolicies::class)->save($this->saudi->id, null, null, $this->economyId, [
            'price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500,
            'addons.pet_fee_minor' => 1000, 'addons.child_seat_fee_minor' => 500, 'addons.priority_fee_minor' => 700,
            'addons.wait_save_discount_bp' => 1000, 'addons.wait_save_delay_s' => 300, 'addons.luggage_capacity' => 4,
        ]);
        app(RidePolicies::class)->save($this->saudi->id, null, null, null, ['loyalty.points_per_unit' => 2, 'loyalty.committed_bonus_bp' => 0]);
        RidePolicies::forget();

        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_add_ons_are_priced_matched_to_equipped_captains_and_paid_to_them(): void
    {
        $plain = $this->quote()->assertOk()->json('data.0');
        $this->assertArrayHasKey('pet', $plain['addons_offer']);
        $this->assertSame(1000, $plain['addons_offer']['pet']['fee_minor']);

        $quote = $this->quote(['options' => ['pet' => true, 'child_seats' => 1, 'service_animal' => true, 'bags_small' => 1, 'bags_large' => 1]])->assertOk()->json('data.0');
        $lines = collect($quote['lines'])->keyBy('code');
        $this->assertSame(1000, $lines['addon_pet']['amount_minor']);
        $this->assertSame(500, $lines['addon_child_seat']['amount_minor']);
        $this->assertSame($plain['total_minor'] + 1500, $quote['total_minor']);
        $this->assertContains('service_animal', array_column($quote['options'], 'key'));

        // Too many bags for the boot: no category here takes them.
        $this->quote(['options' => ['bags_large' => 3]])->assertStatus(422)->assertJsonPath('error_code', 'rides_addons_luggage_too_much');
        // Wait & Save and priority don't go together.
        $this->quote(['options' => ['wait_save' => true, 'priority' => true]])->assertStatus(422)->assertJsonPath('error_code', 'rides_addons_wait_or_priority');

        // Only the captain who takes pets and has a child seat is asked.
        $plainCaptain = $this->onlineDriver();
        $equipped = $this->onlineDriver();
        $this->asDriver($equipped)->putJson('/api/driver/v1/preferences', ['pet_friendly' => true, 'child_seats' => 1])->assertOk()
            ->assertJsonPath('data.equipment.pet_friendly', true)->assertJsonPath('data.equipment.child_seats', 1);
        // O-88: the rider is told how many equipped captains are around (none before, one now); nothing asked = null.
        $this->assertSame(0, $quote['equipped_nearby']);
        $this->assertNull($plain['equipped_nearby']);
        $quote = $this->quote(['options' => ['pet' => true, 'child_seats' => 1, 'service_animal' => true, 'bags_small' => 1, 'bags_large' => 1]])->assertOk()->json('data.0');
        $this->assertSame(1, $quote['equipped_nearby']);
        $this->fund($this->rider, 20000);
        $uuid = $this->book($quote, 'wallet')->assertCreated()->json('data.uuid');
        $this->assertSame(0, RideOffer::query()->where('driver_id', $plainCaptain->id)->count());
        $this->assertSame(1, RideOffer::query()->where('driver_id', $equipped->id)->count());
        // D-59, D-390: the request card says what's added.
        $card = $this->asDriver($equipped)->getJson('/api/driver/v1/offers')->assertOk()->json('data.0');
        $this->assertEqualsCanonicalizing(['pet', 'child_seats', 'service_animal', 'luggage'], array_column(data_get($card, 'ride.options', data_get($card, 'options', [])), 'key'));

        $this->driveToTheEnd($equipped, $uuid);
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame($quote['total_minor'], $ride->final_total_minor);
        $fare = (int) collect($quote['lines'])->firstWhere('code', 'fare')['amount_minor'];
        $this->assertSame($fare - $ride->commission_minor - $ride->tax_minor + 1500, $ride->driver_net_minor, 'the add-on fees are the captain\'s');
    }

    public function test_wait_and_save_takes_dorrs_discount_and_starts_the_search_later(): void
    {
        $plain = $this->quote()->json('data.0');
        $quote = $this->quote(['options' => ['wait_save' => true]])->assertOk()->json('data.0');
        $fare = (int) collect($quote['lines'])->firstWhere('code', 'fare')['amount_minor'];
        $discount = intdiv($fare * 1000 + 5000, 10000);
        $this->assertSame(-$discount, collect($quote['lines'])->firstWhere('code', 'wait_save')['amount_minor']);
        $this->assertSame($plain['total_minor'] - $discount, $quote['total_minor']);

        $captain = $this->onlineDriver();
        $this->fund($this->rider, 20000);
        $uuid = $this->book($quote, 'wallet')->assertCreated()->json('data.uuid');
        $this->assertSame(0, RideOffer::query()->count(), 'nobody asked yet — the rider chose to wait');
        $this->travel(301)->seconds();
        $this->artisan('rides:dispatch-tick')->assertSuccessful();
        $this->assertSame(1, RideOffer::query()->where('driver_id', $captain->id)->count());

        $this->driveToTheEnd($captain, $uuid);
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame($fare - $ride->commission_minor - $ride->tax_minor, $ride->driver_net_minor, 'the captain\'s net is untouched — DORR pays the discount');
    }

    public function test_points_tiers_badges_challenges_and_rewards(): void
    {
        $silver = LoyaltyTier::query()->create(['name' => ['ar' => 'فضي', 'en' => 'Silver'], 'benefits' => ['ar' => ['نقاط ×1.5'], 'en' => ['×1.5 points']], 'min_points' => 10, 'multiplier_bp' => 15000, 'status' => true]);
        LoyaltyTier::query()->create(['name' => ['ar' => 'ذهبي', 'en' => 'Gold'], 'min_points' => 100000, 'multiplier_bp' => 20000, 'status' => true]);
        LoyaltyBadge::query()->create(['name' => ['ar' => 'أول رحلة', 'en' => 'First ride'], 'icon' => 'rocket', 'rule' => 'rides', 'threshold' => 1, 'reward_points' => 5, 'status' => true]);
        LoyaltyChallenge::query()->create(['title' => ['ar' => 'رحلتين', 'en' => 'Two rides'], 'rule' => 'rides', 'target' => 2, 'reward_points' => 50,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addWeek(), 'status' => true]);
        $credit = LoyaltyReward::query()->create(['title' => ['ar' => 'رصيد 10', 'en' => '10 credit'], 'cost_points' => 60, 'kind' => 'wallet_credit', 'country_id' => $this->saudi->id, 'value_minor' => 1000, 'per_user_limit' => 1, 'status' => true]);
        $coupon = LoyaltyReward::query()->create(['title' => ['ar' => 'خصم 20%', 'en' => '20% off'], 'cost_points' => 10, 'kind' => 'coupon', 'country_id' => $this->saudi->id, 'coupon_percent' => 20, 'coupon_max_minor' => 1000, 'min_tier_id' => $silver->id, 'status' => true]);

        $this->fund($this->rider, 50000);
        $captain = $this->onlineDriver();
        $first = $this->rideToTheEnd($captain);
        $paid = (int) $first->final_total_minor;
        $points = intdiv($paid * 2, 100);
        $as = $this->actingAs($this->rider, 'user_api');
        // R-146: on the receipt.
        $as->getJson("/api/mobile/v1/rides/{$first->uuid}")->assertJsonPath('data.loyalty_points', $points);

        // The first-ride badge (+5) put the rider in Silver.
        $dash = $as->getJson('/api/mobile/v1/rides/loyalty')->assertOk()->assertJsonPath('data.enabled', true)->json('data');
        $this->assertSame($points + 5, $dash['balance']);
        $this->assertSame($silver->id, $dash['tier']['id']);
        $this->assertNotNull($dash['badges'][0]['earned_at']);
        $this->assertSame(1, $dash['challenges'][0]['progress']);
        $this->assertCount(2, $dash['tiers']);

        // The second ride: ×1.5 in Silver, and the challenge (+50).
        $second = $this->rideToTheEnd($captain);
        $points2 = intdiv(intdiv((int) $second->final_total_minor * 2, 100) * 15000 + 5000, 10000);
        $dash = $as->getJson('/api/mobile/v1/rides/loyalty')->json('data');
        $this->assertSame($points + 5 + $points2 + 50, $dash['balance']);
        $this->assertNotNull($dash['challenges'][0]['completed_at']);

        // Rewards: wallet credit (once per rider), and a Silver-only coupon.
        $before = app(WalletService::class)->firstOrCreateWallet($this->rider, $this->saudi)->fresh()->spend_only_minor;
        $as->postJson("/api/mobile/v1/rides/loyalty/rewards/{$credit->id}/redeem", [], ['Idempotency-Key' => 'r-1'])->assertCreated()->assertJsonPath('data.result.credit_minor', 1000);
        $as->postJson("/api/mobile/v1/rides/loyalty/rewards/{$credit->id}/redeem", [], ['Idempotency-Key' => 'r-1'])->assertCreated();
        $this->assertSame($before + 1000, app(WalletService::class)->firstOrCreateWallet($this->rider, $this->saudi)->fresh()->spend_only_minor, 'the same key spends once');
        $as->postJson("/api/mobile/v1/rides/loyalty/rewards/{$credit->id}/redeem", [], ['Idempotency-Key' => 'r-2'])->assertStatus(409)->assertJsonPath('error_code', 'rides_loyalty_reward_limit');
        $code = $as->postJson("/api/mobile/v1/rides/loyalty/rewards/{$coupon->id}/redeem", [], ['Idempotency-Key' => 'r-3'])->assertCreated()->json('data.result.coupon_code');
        $this->assertSame('percent', WalletCoupon::query()->where('code', $code)->value('kind'));
        $as->getJson('/api/mobile/v1/rides/loyalty/history?kind=spent')->assertOk()->assertJsonCount(2, 'data');
        $as->getJson('/api/mobile/v1/rides/loyalty/redemptions')->assertOk()->assertJsonCount(2, 'data');
        $balance = $as->getJson('/api/mobile/v1/rides/loyalty')->json('data.balance');
        $this->assertSame($points + 5 + $points2 + 50 - 70, $balance);

        // Not enough points.
        $big = LoyaltyReward::query()->create(['title' => ['ar' => 'كبير', 'en' => 'Big'], 'cost_points' => 999999, 'kind' => 'wallet_credit', 'country_id' => $this->saudi->id, 'value_minor' => 1, 'status' => true]);
        $as->getJson('/api/mobile/v1/rides/loyalty/rewards')->assertOk()->assertJsonFragment(['id' => $big->id, 'available' => false, 'reason' => 'points']);
        $as->postJson("/api/mobile/v1/rides/loyalty/rewards/{$big->id}/redeem", [], ['Idempotency-Key' => 'r-4'])->assertStatus(422)->assertJsonPath('error_code', 'rides_loyalty_not_enough_points');
    }

    public function test_the_program_stays_off_where_it_is_not_switched_on(): void
    {
        RideFeatureRule::query()->where('feature', 'loyalty')->update(['enabled' => false]);
        RideFeatures::forget();
        $this->fund($this->rider, 50000);
        $ride = $this->rideToTheEnd($this->onlineDriver());
        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/loyalty')->assertOk()->assertJsonPath('data.enabled', false);
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/{$ride->uuid}")->assertJsonPath('data.loyalty_points', null);
    }

    private function rideToTheEnd(Driver $captain): Ride
    {
        $quote = $this->quote()->json('data.0');
        $uuid = $this->book($quote, 'wallet')->assertCreated()->json('data.uuid');
        $this->driveToTheEnd($captain, $uuid);

        return Ride::query()->where('uuid', $uuid)->firstOrFail();
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

    private function driveToTheEnd(Driver $driver, string $uuid): void
    {
        $offer = RideOffer::query()->where('driver_id', $driver->id)->where('status', 'sent')->latest('id')->firstOrFail();
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
}
