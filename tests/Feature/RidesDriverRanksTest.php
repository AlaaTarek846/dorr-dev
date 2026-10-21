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
 * Captain ranks and performance (D-5, D-6, D-239..D-259) through real rides.
 */
class RidesDriverRanksTest extends TestCase
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

    public function test_ranks_probation_up_warning_down_and_appeal(): void
    {
        $captain = $this->onlineDriver();
        // D-5, D-6: just activated — «new», with the days left.
        $captain->forceFill(['activated_at' => now()])->save();
        $this->asDriver($captain)->getJson('/api/driver/v1/rank')->assertOk()
            ->assertJsonPath('data.probation.active', true)->assertJsonPath('data.rank.slug', 'new')->assertJsonPath('data.probation.days_left', 30);

        // Past the probation, one ride: bronze, and silver shows what is missing.
        $captain->forceFill(['activated_at' => now()->subDays(60)])->save();
        $this->fund($this->rider, 50000);
        $this->rideToTheEnd($captain);
        $rank = $this->asDriver($captain)->getJson('/api/driver/v1/rank')->assertOk()->assertJsonPath('data.rank.slug', 'bronze')->json('data');
        $this->assertSame(1, $rank['metrics']['trips']);
        $this->assertContains('trips', array_column($rank['next']['missing'], 'metric'));

        // The admin lowers silver to one trip: up at once.
        \Modules\Rides\Models\DriverRank::query()->where('slug', 'silver')->update(['min_trips' => 1, 'min_rating' => 0, 'min_completion_bp' => 0, 'max_cancel_bp' => 10000]);
        $this->asDriver($captain)->getJson('/api/driver/v1/rank')->assertJsonPath('data.rank.slug', 'silver');

        // Silver asks for more again: a warning with a grace, then down — on the record, appealable.
        \Modules\Rides\Models\DriverRank::query()->where('slug', 'silver')->update(['min_trips' => 5]);
        $falling = $this->asDriver($captain)->getJson('/api/driver/v1/rank')->assertJsonPath('data.rank.slug', 'silver')->json('data.falling');
        $this->assertSame('bronze', $falling['to']['slug']);
        $this->travel(8)->days();
        $data = $this->asDriver($captain)->getJson('/api/driver/v1/rank')->assertJsonPath('data.rank.slug', 'bronze')->json('data');
        $change = collect($data['notes'])->firstWhere('kind', 'rank_change');
        $this->assertNotNull($change);
        $this->asDriver($captain)->postJson("/api/driver/v1/rank/notes/{$change['id']}/appeal", ['text' => 'The trips were cancelled by riders'])->assertOk()->assertJsonPath('data.appeal_status', 'pending');
        $this->asDriver($captain)->postJson("/api/driver/v1/rank/notes/{$change['id']}/appeal", ['text' => 'again please'])->assertStatus(409);
        $events = $captain->notifications()->get()->map(fn ($n) => $n->data['event'] ?? null)->all();
        $this->assertSame(4, collect($events)->filter(fn ($e) => $e === 'driver.rank')->count(), 'out of probation, up, the warning, down');

        // D-254: a category can ask for a rank.
        $vip = RideCategory::query()->create(['slug' => 'vip', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 2, 'capacity_max' => 4, 'flags' => [], 'eligibility' => ['min_rank' => 'gold']]);
        $vehicle = app(\Modules\Rides\Services\Drivers\DriverEligibility::class)->activeVehicle($captain);
        $this->assertSame('driver_rank', app(\Modules\Rides\Services\Drivers\DriverEligibility::class)->categoryProblem($captain, $vehicle, $vip));
    }

    public function test_payout_safeguards_invoices_and_the_emergency_freeze(): void
    {
        $captain = $this->onlineDriver();
        app(\Modules\Wallet\Services\PinService::class)->set($captain, '1234');
        $h = ['X-Wallet-Pin' => '1234', 'X-Country' => 'SA'];
        $bank = fn (array $data) => ['type' => 'bank', 'data' => $data + ['bank_name' => 'Al Rajhi', 'iban' => 'SA0380000000608010167519']];

        // D-219: an account in someone else's name needs the captain's word for it.
        $this->asDriver($captain)->postJson('/api/driver/v1/wallet/withdrawal-methods', $bank(['account_holder' => 'Someone Else']), $h)
            ->assertStatus(422)->assertJsonPath('error_code', 'rides_payout_third_party_ack');
        // D-223: abroad is off until approved.
        $this->asDriver($captain)->postJson('/api/driver/v1/wallet/withdrawal-methods', ['type' => 'bank', 'data' => ['bank_name' => 'NBE', 'account_holder' => $captain->name, 'iban' => 'EG380019000500000000263180002']], $h)
            ->assertStatus(422)->assertJsonPath('error_code', 'rides_payout_international_off');
        $method = $this->asDriver($captain)->postJson('/api/driver/v1/wallet/withdrawal-methods', $bank(['account_holder' => $captain->name]), $h)->assertCreated()->json('data.id');
        $this->assertContains('driver.payout_method', $captain->notifications()->get()->map(fn ($n) => $n->data['event'] ?? null)->all());

        // D-224: ask; the team approves; the account abroad goes in.
        $this->asDriver($captain)->postJson('/api/driver/v1/payouts/international', ['reason' => 'I live in Egypt part of the year'])->assertOk()->assertJsonPath('data.international.status', 'pending');
        app(\Modules\Rides\Services\Drivers\DriverPayouts::class)->answerInternational($captain, true, 'OK');
        $this->asDriver($captain)->postJson('/api/driver/v1/wallet/withdrawal-methods', ['type' => 'bank', 'data' => ['bank_name' => 'NBE', 'account_holder' => $captain->name, 'iban' => 'EG380019000500000000263180002']], $h)->assertCreated();

        // D-216: not verified yet — no withdrawal; D-218: a new account waits its hold.
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($captain, $this->saudi), 50000, WalletBucket::Withdrawable, WalletTransactionType::ServiceEarning, ['idempotency_key' => 'earn:'.$captain->id]);
        $captain->forceFill(['activated_at' => null])->save();
        $withdraw = fn (string $key) => $this->asDriver($captain)->postJson('/api/driver/v1/wallet/withdrawals', ['withdrawal_method_id' => $method, 'amount_minor' => 10000], $h + ['Idempotency-Key' => $key]);
        $withdraw('withdraw-key-1')->assertStatus(403)->assertJsonPath('error_code', 'rides_payout_kyc_required');
        $captain->forceFill(['activated_at' => now()->subDays(60)])->save();
        $withdraw('withdraw-key-2')->assertStatus(423)->assertJsonPath('error_code', 'rides_payout_method_cooling');
        $this->travel(25)->hours();
        $withdraw('withdraw-key-3')->assertCreated();

        // D-227: this month's statement.
        DriverPresence::query()->where('driver_id', $captain->id)->update(['last_seen_at' => now(), 'online_since' => now()]);
        $this->fund($this->rider, 50000);
        $this->rideToTheEnd($captain);
        $month = $this->asDriver($captain)->getJson('/api/driver/v1/invoices')->assertOk()->json('data.0');
        $this->assertSame(1, $month['trips']);
        $this->asDriver($captain)->getJson("/api/driver/v1/invoices/{$month['month']}")->assertOk()->assertJsonCount(1, 'data.lines');

        // D-299: the phone is lost — freeze with a code to the number, from anywhere.
        $phone = substr($captain->phone, 4);
        $this->postJson('/api/driver/v1/emergency/freeze/request', ['dial_code' => '+966', 'phone' => $phone])->assertOk();
        $this->postJson('/api/driver/v1/emergency/freeze/confirm', ['dial_code' => '+966', 'phone' => $phone, 'code' => '1234'])->assertOk()->assertJsonPath('data.frozen', true);
        $this->assertSame(0, $captain->tokens()->count());
        $this->travel(2)->hours();
        $withdraw('withdraw-key-4')->assertStatus(423)->assertJsonPath('error_code', 'rides_payout_frozen');
    }

    public function test_captain_support_tickets_and_lost_items(): void
    {
        $captain = $this->onlineDriver();
        $this->fund($this->rider, 50000);
        $ride = $this->rideToTheEnd($captain);

        // D-286: the help centre for captains (a riders-only article stays out).
        \Modules\User\Models\SupportHelpNode::query()->create(['status' => true, 'audience' => 'driver', 'sort_order' => 1])->fillAllTranslations(['title' => ['ar' => 'السحب', 'en' => 'Withdrawals'], 'answer' => ['ar' => 'من المحفظة', 'en' => 'From the wallet']])->save();
        $help = $this->asDriver($captain)->getJson('/api/driver/v1/support/help?q=wallet')->assertOk()->json('data');
        $this->assertCount(1, $help['items']);
        $this->assertCount(1, $help['results']);
        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/support-help')->assertOk()->assertJsonCount(0, 'data.items');

        // D-288..D-290: a ticket about one of my rides, in a category; the team answers it on the same dashboard.
        $id = $this->asDriver($captain)->postJson('/api/driver/v1/support/tickets', ['title' => 'Fare', 'body' => 'The fare looks wrong', 'category' => 'trip', 'ride_uuid' => $ride->uuid])
            ->assertCreated()->json('data.id');
        $ticket = \Modules\User\Models\SupportTicket::query()->findOrFail($id);
        $this->assertSame($captain->id, $ticket->driver_id);
        $this->assertSame($ride->id, $ticket->ride_id);
        $this->asDriver($captain)->postJson("/api/driver/v1/support/tickets/$id/messages", ['body' => 'Any news?'])->assertSuccessful();
        // Mine, and the team's automatic acknowledgement, and my follow-up.
        $this->assertGreaterThanOrEqual(2, count($this->asDriver($captain)->getJson("/api/driver/v1/support/tickets/$id/messages")->assertOk()->json('data')));
        $this->asDriver($captain)->getJson('/api/driver/v1/support/tickets')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/support-tickets')->assertOk()->assertJsonCount(0, 'data');

        // D-294..D-297: the rider left a bag; the captain finds it, they talk in the app, the return is recorded.
        $lost = $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/{$ride->uuid}/lost-item", ['description' => 'A black bag'])->assertCreated()->json('data.uuid');
        $this->assertContains('lost.reported', $captain->notifications()->get()->map(fn ($n) => $n->data['event'] ?? null)->all());
        $this->asDriver($captain)->getJson('/api/driver/v1/lost-items')->assertOk()->assertJsonPath('data.0.description', 'A black bag');
        $this->asDriver($captain)->postJson("/api/driver/v1/lost-items/$lost/returned")->assertStatus(409);
        $this->asDriver($captain)->postJson("/api/driver/v1/lost-items/$lost/answer", ['found' => true, 'plan' => 'Olaya mall gate 3 at 8pm'])->assertOk()->assertJsonPath('data.status', 'found');
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/lost-items/$lost/messages", ['body' => 'Thanks! I will be there'])->assertCreated();
        $this->asDriver($captain)->getJson("/api/driver/v1/lost-items/$lost")->assertOk()->assertJsonPath('data.messages.0.mine', false);
        $this->asDriver($captain)->post("/api/driver/v1/lost-items/$lost/returned", ['photo' => \Illuminate\Http\UploadedFile::fake()->image('bag.jpg')], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'returned');
        $events = $this->rider->notifications()->get()->map(fn ($n) => $n->data['event'] ?? null)->all();
        $this->assertContains('lost.found', $events);
        $this->assertContains('lost.returned', $events);
    }

    public function test_captain_account_settings_phone_terms_and_closing(): void
    {
        $captain = $this->onlineDriver();
        $as = fn () => $this->asDriver($captain);

        // D-304: how a request rings.
        $as()->putJson('/api/driver/v1/account/alerts', ['sound' => false, 'voice' => true])->assertOk()->assertJsonPath('data.sound', false)->assertJsonPath('data.vibration', true)->assertJsonPath('data.voice', true);

        // D-303: delete a notification, clear the read ones.
        $captain->notify(new \App\Notifications\GeneralNotification('driver.test', [], '', 'x', 'y', [], 'Success', 'raw'));
        $id = $captain->notifications()->value('id');
        $as()->deleteJson("/api/driver/v1/notifications/$id")->assertOk();
        $this->assertSame(0, $captain->notifications()->count());

        // D-314: a new number with a code.
        $as()->postJson('/api/driver/v1/account/phone', ['dial_code' => '+966', 'phone' => '551234567'])->assertOk();
        $as()->postJson('/api/driver/v1/account/phone/confirm', ['code' => '0000'])->assertStatus(422)->assertJsonPath('error_code', 'rides_account_code_wrong');
        $as()->postJson('/api/driver/v1/account/phone/confirm', ['code' => '1234'])->assertOk()->assertJsonPath('data.phone', '+966551234567');

        // D-317, D-318: the terms in force; where required, newer terms keep the captain offline until accepted.
        $page = \App\Models\LegalPage::query()->create(['type' => 'term', 'status' => true]);
        $page->translations()->create(['locale' => 'en', 'content' => 'Terms v1']);
        app(RidePolicies::class)->save($this->saudi->id, null, null, null, ['loyalty.points_per_unit' => 2, 'loyalty.committed_bonus_bp' => 0, 'drivers.require_terms' => true]);
        RidePolicies::forget();
        $legal = $as()->getJson('/api/driver/v1/account/legal')->assertOk()->json('data.0');
        $this->assertTrue($legal['needs_acceptance']);
        $this->assertSame('terms_pending', app(\Modules\Rides\Services\Drivers\DriverEligibility::class)->reasonNotToWork($captain->refresh()));
        $as()->postJson('/api/driver/v1/account/legal/accept', ['types' => ['term']])->assertOk()->assertJsonPath('data.0.needs_acceptance', false);
        $this->assertNull(app(\Modules\Rides\Services\Drivers\DriverEligibility::class)->reasonNotToWork($captain->refresh()));
        $this->travel(1)->minutes();
        $page->forceFill(['updated_at' => now()])->save();
        $as()->getJson('/api/driver/v1/account/legal')->assertJsonPath('data.0.needs_acceptance', true);

        // D-316, D-226: money in the wallet blocks closing; withdrawn, it closes.
        $wallets = app(WalletService::class);
        $wallet = $wallets->firstOrCreateWallet($captain, $this->saudi);
        $wallets->credit($wallet, 1000, WalletBucket::Withdrawable, WalletTransactionType::ServiceEarning, ['idempotency_key' => 'c:'.$captain->id]);
        $as()->getJson('/api/driver/v1/account/close')->assertOk()->assertJsonPath('data.can_close', false)->assertJsonPath('data.blockers.0.code', 'balance');
        $as()->postJson('/api/driver/v1/account/close', ['reason' => 'Moving', 'confirm' => true])->assertStatus(409);
        \Modules\Wallet\Models\Wallet::query()->whereKey($wallet->id)->update(['withdrawable_minor' => 0]);
        $as()->postJson('/api/driver/v1/account/close', ['reason' => 'Moving', 'confirm' => true])->assertOk();
        $this->assertNotNull($captain->refresh()->closed_at);
        $this->assertSame(0, $captain->tokens()->count());
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
