<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\NotificationDevice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Sports\Database\Seeders\SportsSeeder;
use Modules\Sports\Events\MatchChanged;
use Modules\Sports\Models\SportsCompetition;
use Modules\Sports\Models\SportsContest;
use Modules\Sports\Models\SportsContestWinner;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Models\SportsSetting;
use Modules\Sports\Models\SportsSport;
use Modules\Sports\Models\SportsTeam;
use Modules\User\Models\User;
use Modules\Wallet\Contracts\CheckoutPurpose;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Models\WalletCoupon;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletService;
use Modules\Wallet\Support\Payments\CheckoutLine;
use Modules\Wallet\Support\Payments\CheckoutPurposes;
use Tests\TestCase;

/**
 * DORR Sports predictions and prize contests (spec 196, 199; docs/sports-plan.md §5): free
 * predictions locked at kick-off and scored from the official result, contests that only pay in
 * countries opened for prizes, one winner per phone, wallet credit that can't be withdrawn, coupons
 * spent on the payment screen, and the admin's review.
 */
class SportsPredictionsTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private User $alice;

    private User $bob;

    private User $carol;

    private SportsMatch $match;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id, 'status' => true]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }
        $this->seed([SportsSeeder::class, FinancialCategorySeeder::class]);
        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        $this->carol = $make('Carol', '+966500000003');
        foreach (['alice' => $this->alice, 'bob' => $this->bob, 'carol' => $this->carol] as $name => $u) {
            NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $u->id, 'player_id' => $name.'-phone', 'platform' => 'android']);
        }
        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1'])]);
        $this->travelTo(Carbon::parse('2026-10-20 12:00', 'UTC'));

        $sport = SportsSport::query()->where('key', 'football')->firstOrFail();
        $comp = SportsCompetition::query()->create(['sport_id' => $sport->id, 'provider_id' => 307, 'name' => 'Pro League', 'season' => '2026', 'tier' => 'big']);
        $home = SportsTeam::query()->create(['sport_id' => $sport->id, 'provider_id' => 1, 'name' => 'Al-Hilal']);
        $away = SportsTeam::query()->create(['sport_id' => $sport->id, 'provider_id' => 2, 'name' => 'Al-Nassr']);
        $this->match = SportsMatch::query()->create([
            'uuid' => (string) Str::uuid(), 'sport_id' => $sport->id, 'competition_id' => $comp->id, 'provider_id' => 1001, 'round' => 'R8',
            'home_team_id' => $home->id, 'away_team_id' => $away->id, 'starts_at' => now()->addHours(5), 'status' => 'scheduled',
        ]);
    }

    public function test_predictions_lock_at_kick_off_and_are_scored_from_the_official_result(): void
    {
        $this->as($this->alice)->postJson($this->url('prediction'), ['home_score' => 2, 'away_score' => 1], $this->headers())->assertOk()
            ->assertJsonPath('data.mine.winner', 'home')->assertJsonPath('data.crowd.count', 1);
        $this->as($this->bob)->postJson($this->url('prediction'), ['winner' => 'home'], $this->headers())->assertOk();
        $this->as($this->carol)->postJson($this->url('prediction'), ['winner' => 'away'], $this->headers())->assertOk()
            ->assertJsonPath('data.crowd.home', 67)->assertJsonPath('data.crowd.away', 33);

        // Kick-off: no more changes.
        $this->travelTo(now()->addHours(5)->addMinute());
        $this->match->update(['status' => 'live']);
        $this->as($this->alice)->postJson($this->url('prediction'), ['home_score' => 0, 'away_score' => 0], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'sports_prediction_locked');

        $this->finish(2, 1);
        $this->as($this->alice)->getJson($this->url('play'), $this->headers())->assertOk()
            ->assertJsonPath('data.mine.points', 3)->assertJsonPath('data.mine.exact', true);
        $this->as($this->bob)->getJson($this->url('play'), $this->headers())->assertJsonPath('data.mine.points', 1);
        $this->as($this->carol)->getJson($this->url('play'), $this->headers())->assertJsonPath('data.mine.result', 'lost');

        // The community's rating after the whistle — clearly not an official one (199).
        $this->as($this->carol)->postJson($this->url('rating'), ['fun' => 5, 'excitement' => 4], $this->headers())->assertOk()
            ->assertJsonPath('data.rating.count', 1)->assertJsonPath('data.rating.fun', 5);
    }

    public function test_a_wallet_prize_pays_only_where_prizes_are_open_one_per_phone_and_cannot_be_withdrawn(): void
    {
        $contest = $this->contest(['prize_type' => 'wallet', 'rule' => 'winner', 'distribution' => 'each', 'prize_amounts' => [(string) $this->saudi->id => 1000]]);
        // Bob's account shares Alice's phone: only one of them may win.
        foreach ([$this->alice, $this->bob] as $u) {
            DB::table('wallet_trusted_devices')->insert(['owner_type' => 'user', 'owner_id' => $u->id, 'device_id' => 'same-phone', 'trusted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([$this->alice, $this->bob, $this->carol] as $u) {
            $this->as($u)->postJson($this->url('prediction'), ['winner' => $u->is($this->carol) ? 'away' : 'home'], $this->headers())->assertOk();
        }

        // Prizes closed in Saudi (the default): nobody is paid.
        $this->finish(1, 0);
        $this->assertSame('settled', $contest->refresh()->status);
        $this->assertSame(0, SportsContestWinner::query()->count());

        // Opened (after the legal review): one winner per phone, credit that can't be withdrawn.
        SportsSetting::query()->firstOrFail()->update(['prizes_countries' => [$this->saudi->id]]);
        $contest2 = $this->contest(['prize_type' => 'wallet', 'rule' => 'winner', 'distribution' => 'each', 'prize_amounts' => [(string) $this->saudi->id => 1000]]);
        app(\Modules\Sports\Services\ContestService::class)->settle($contest2);
        $winners = SportsContestWinner::query()->where('contest_id', $contest2->id)->get();
        $this->assertCount(1, $winners);
        $this->assertSame('paid', $winners[0]->status);
        $owner = User::query()->find($winners[0]->owner_id);
        $wallet = app(WalletService::class)->firstOrCreateWallet($owner, $this->saudi)->refresh();
        $this->assertSame(1000, $wallet->availableMinor(WalletBucket::SpendOnly));
        $this->assertSame(0, $wallet->availableMinor(WalletBucket::Withdrawable));
        $this->assertDatabaseHas('wallet_transactions', ['wallet_id' => $wallet->id, 'type' => WalletTransactionType::Reward->value, 'amount_minor' => 1000]);
        $this->assertSame(1, DB::table('financial_entries')->join('financial_categories', 'financial_categories.id', '=', 'financial_entries.category_id')->where('financial_categories.slug', 'prize_cost')->count());

        $this->as($owner)->getJson('/api/mobile/v1/sports/prizes', $this->headers())->assertOk()->assertJsonPath('data.0.status', 'paid')->assertJsonPath('data.0.amount_minor', 1000);
    }

    public function test_a_coupon_prize_is_spent_on_the_payment_screen_once(): void
    {
        SportsSetting::query()->firstOrFail()->update(['prizes_countries' => [$this->saudi->id]]);
        $this->contest(['prize_type' => 'coupon', 'rule' => 'exact', 'distribution' => 'each', 'prize_amounts' => [(string) $this->saudi->id => 2000], 'coupon' => ['kind' => 'fixed', 'valid_days' => 30]]);
        $this->as($this->alice)->postJson($this->url('prediction'), ['home_score' => 3, 'away_score' => 0], $this->headers())->assertOk();
        $this->finish(3, 0);

        $coupon = WalletCoupon::query()->firstOrFail();
        $this->assertSame([2000, 'fixed', 'user'], [$coupon->value, $coupon->kind, $coupon->owner_type]);
        $this->as($this->alice)->getJson('/api/mobile/v1/wallet/coupons', $this->headers())->assertOk()->assertJsonPath('data.0.code', $coupon->code);

        // A 50.00 item: with the coupon, 30.00 is charged.
        app(CheckoutPurposes::class)->register('test_item', TestItemPurpose::class);
        app(PinService::class)->set($this->alice, '1234');
        app(WalletService::class)->credit(app(WalletService::class)->firstOrCreateWallet($this->alice, $this->saudi), 3000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $checkout = $this->postJson('/api/mobile/v1/wallet/checkouts', ['purpose' => 'test_item', 'reference' => []], $this->headers())->assertCreated()->json('data.id');
        $this->as($this->bob)->postJson("/api/mobile/v1/wallet/checkouts/{$checkout}/coupon", ['code' => $coupon->code], $this->headers())->assertNotFound();
        $this->as($this->alice)->postJson("/api/mobile/v1/wallet/checkouts/{$checkout}/coupon", ['code' => strtolower($coupon->code)], $this->headers())->assertOk()
            ->assertJsonPath('data.discount_minor', 2000)->assertJsonPath('data.payable_minor', 3000)->assertJsonPath('data.wallet.enough', true);
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$checkout}/pay", ['method' => 'wallet'], $this->headers() + ['X-Wallet-Pin' => '1234'])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame('used', $coupon->refresh()->status);
        $this->assertDatabaseHas('wallet_coupon_redemptions', ['coupon_id' => $coupon->id, 'discount_minor' => 2000]);
        $this->assertSame(0, app(WalletService::class)->firstOrCreateWallet($this->alice, $this->saudi)->refresh()->availableMinor(WalletBucket::Withdrawable));

        // Again: already used.
        $again = $this->postJson('/api/mobile/v1/wallet/checkouts', ['purpose' => 'test_item', 'reference' => []], $this->headers())->json('data.id');
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$again}/coupon", ['code' => $coupon->code], $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'coupon_used');
    }

    public function test_a_big_prize_waits_for_the_admin_who_pays_or_refuses_it(): void
    {
        SportsSetting::query()->firstOrFail()->update(['prizes_countries' => [$this->saudi->id]]);
        $this->asAdmin(['sports-contests.view', 'sports-contests.create', 'sports-contests.update']);
        $id = $this->postJson('/api/admin/v1/sports-contests', [
            'scope' => 'match', 'match_id' => $this->match->uuid, 'rule' => 'winner', 'prize_type' => 'wallet', 'distribution' => 'split',
            'prize_amounts' => [(string) $this->saudi->id => 50000], 'review_above_minor' => 10000, 'min_account_days' => 0,
            'translations' => [['locale' => 'en', 'name' => 'Derby day']],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->postJson("/api/admin/v1/sports-contests/{$id}/open")->assertOk()->assertJsonPath('data.status', 'open');

        $this->as($this->carol)->getJson('/api/mobile/v1/sports/contests', $this->headers())->assertOk()->assertJsonPath('data.0.name', 'Derby day')
            ->assertJsonPath('data.0.prize_amount_minor', 50000)->assertJsonPath('data.0.prizes_here', true);
        $this->postJson($this->url('prediction'), ['winner' => 'draw'], $this->headers())->assertOk();
        $this->as($this->alice)->postJson($this->url('prediction'), ['winner' => 'draw'], $this->headers())->assertOk();
        $this->finish(1, 1);

        // Split between two: 250.00 each — above the review amount, so both wait.
        $this->asAdmin(['sports-contests.view', 'sports-contests.update']);
        $show = $this->getJson("/api/admin/v1/sports-contests/{$id}")->assertOk()->assertJsonPath('data.status', 'settled')->assertJsonCount(2, 'data.winners');
        $this->assertSame(['review', 'review'], array_column($show->json('data.winners'), 'status'));
        $this->assertSame([25000, 25000], array_column($show->json('data.winners'), 'amount_minor'));
        [$first, $second] = $show->json('data.winners');
        $this->patchJson("/api/admin/v1/sports-contests/{$id}/winners/{$first['id']}", ['status' => 'paid'])->assertOk();
        $this->patchJson("/api/admin/v1/sports-contests/{$id}/winners/{$second['id']}", ['status' => 'rejected', 'note' => 'Duplicate account'])->assertOk();
        $this->assertSame(['paid', 'rejected'], SportsContestWinner::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame(1, DB::table('wallet_transactions')->where('type', 'reward')->count());
    }

    public function test_a_match_is_shared_in_a_chat_as_a_card_with_a_who_wins_poll(): void
    {
        $this->as($this->alice);
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->assertOk()->json('data.id');
        $r = $this->postJson($this->url('share'), ['conversation_id' => $chat, 'poll' => true], $this->headers())->assertCreated()->json('data');
        $card = \Modules\Chat\Models\ChatMessage::query()->where('uuid', $r['message_id'])->firstOrFail();
        $this->assertSame('match_card', $card->type->value);
        $this->assertSame('Al-Hilal', $card->meta['match']['home']['name']);
        $this->assertSame(['Al-Hilal', 'Draw', 'Al-Nassr'], array_column(\Modules\Chat\Models\ChatMessage::query()->where('uuid', $r['poll_id'])->first()->meta['options'], 'text'));
    }

    // ------------------------------------------------------------------ helpers

    private function finish(int $home, int $away): void
    {
        $this->match->update(['status' => 'finished', 'home_score' => $home, 'away_score' => $away, 'winner' => $home > $away ? 'home' : ($home < $away ? 'away' : 'draw')]);
        event(new MatchChanged($this->match->refresh(), [['type' => 'finished']]));
    }

    /** @param  array<string, mixed>  $attributes */
    private function contest(array $attributes): SportsContest
    {
        $c = SportsContest::query()->create($attributes + ['uuid' => (string) Str::uuid(), 'scope' => 'match', 'match_id' => $this->match->id, 'competition_id' => $this->match->competition_id,
            'status' => 'open', 'min_account_days' => 0, 'auto_pay' => true]);
        $c->translations()->create(['locale' => 'en', 'name' => 'Derby']);

        return $c;
    }

    private function url(string $what): string
    {
        return "/api/mobile/v1/sports/matches/{$this->match->uuid}/{$what}";
    }

    private function asAdmin(array $permissions): void
    {
        $admin = \Modules\Admin\Models\Admin::query()->firstOrCreate(['email' => 'a@example.com'], ['name' => 'A', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            \Spatie\Permission\Models\Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user, [], 'user_api');

        return $this;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }
}

/** A thing to buy in the tests: 50.00, delivers nothing. */
class TestItemPurpose implements CheckoutPurpose
{
    public function line(Model $owner, Country $country, array $reference): CheckoutLine
    {
        return new CheckoutLine(5000, 'Test item', null, []);
    }

    public function fulfil(Checkout $checkout): void {}
}
