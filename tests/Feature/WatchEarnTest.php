<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Discover\Models\DiscoverCity;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\FinancialEntry;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletService;
use Modules\WatchEarn\Enums\AdStatus;
use Modules\WatchEarn\Models\Ad;
use Modules\WatchEarn\Models\AdWallet;
use Modules\WatchEarn\Models\CommentReport;
use Modules\WatchEarn\Models\Reward;
use Modules\WatchEarn\Models\WeSetting;
use Modules\Wallet\Services\CheckoutService;
use Modules\WatchEarn\Services\AdService;
use Modules\WatchEarn\Services\AdWalletService;
use Modules\WatchEarn\Services\AdWalletTopupPurpose;
use Modules\WatchEarn\Services\CommentReportService;
use Modules\WatchEarn\Services\ViewService;
use Tests\TestCase;

/**
 * Watch & Earn (docs/watch_earn_tasks.md): the money by bucket (D12–D14), the ad's life, the server-timed view, the
 * interaction reward, the unclaimed interaction share (D8), city targeting (D7, D10) and reports (WE-035).
 */
class WatchEarnTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private User $advertiser;

    private User $viewer;

    private DiscoverCity $riyadh;

    private DiscoverCity $jeddah;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $flag = Flag::create(['code' => 'sa']);
        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }
        $this->riyadh = DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 24.7136, 'lng' => 46.6753, 'status' => true]);
        $this->jeddah = DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 21.4858, 'lng' => 39.1925, 'status' => true]);

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'gender' => 'male', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->advertiser = $make('Ads Co', '+966500000001');
        $this->viewer = $make('Viewer', '+966500000002');
        app(PinService::class)->set($this->advertiser, '1234');

        // 3.00 a view = 1.00 platform + 1.50 view reward + 0.50 interaction reward. No waits.
        WeSetting::query()->create(array_merge(WeSetting::defaults(), [
            'country_id' => $this->saudi->id, 'enabled' => true, 'price_per_view_minor' => 300, 'platform_share_minor' => 100,
            'view_reward_minor' => 150, 'interaction_reward_minor' => 50, 'image_seconds' => 20, 'report_threshold' => 2,
        ]));

        // The owner's example: 10,000 in the wallet — 5,000 withdrawable and 5,000 that can't be withdrawn.
        $wallets = app(WalletService::class);
        $wallet = $wallets->firstOrCreateWallet($this->advertiser, $this->saudi);
        $wallets->credit($wallet, 500000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $wallets->credit($wallet, 500000, WalletBucket::SpendOnly, WalletTransactionType::TopupBonus);
    }

    public function test_funding_takes_the_money_that_cant_be_withdrawn_first_and_returns_each_part_to_its_bucket(): void
    {
        $this->asAdvertiser();
        $this->postJson('/api/mobile/v1/watch-earn/ad-wallet/fund/preview', ['amount_minor' => 700000], $this->headers())->assertOk()
            ->assertJsonPath('data.take', ['reward_minor' => 0, 'spend_only_minor' => 500000, 'withdrawable_minor' => 200000]);

        // Without the PIN it doesn't move.
        $this->postJson('/api/mobile/v1/watch-earn/ad-wallet/fund', ['amount_minor' => 700000, 'idempotency_key' => 'f-1'], ['X-Country' => 'SA'])->assertStatus(422);
        $this->assertSame(0, AdWallet::query()->count());

        $this->fund(700000, 'same');
        $this->fund(700000, 'same'); // the same key again: nothing more moves

        $wallet = $this->mainWallet();
        $this->assertSame(300000, $wallet->withdrawable_minor);
        $this->assertSame(0, $wallet->spend_only_minor);
        $adWallet = AdWallet::query()->sole();
        $this->assertSame([500000, 200000], [$adWallet->spend_only_minor, $adWallet->withdrawable_minor]);

        // Back to the wallet: withdrawable first, each part into its own bucket.
        $this->postJson('/api/mobile/v1/watch-earn/ad-wallet/return', ['amount_minor' => 300000, 'idempotency_key' => 'r-1'], $this->headers())->assertOk()
            ->assertJsonPath('data.free', ['reward_minor' => 0, 'spend_only_minor' => 400000, 'withdrawable_minor' => 0]);
        $wallet = $this->mainWallet();
        $this->assertSame(500000, $wallet->withdrawable_minor);
        $this->assertSame(100000, $wallet->spend_only_minor);
    }

    public function test_a_view_is_timed_by_the_server_charged_once_and_rewards_the_viewer(): void
    {
        $ad = $this->approvedAd(budget: 3000);

        $this->asViewer();
        $this->getJson('/api/mobile/v1/watch-earn/feed', $this->headers())->assertOk()->assertJsonPath('data.ads.0.id', $ad->uuid)
            ->assertJsonPath('data.player.image_seconds', 20);
        $view = $this->postJson("/api/mobile/v1/watch-earn/ads/{$ad->uuid}/start", [], $this->headers())->assertOk()->json('data.view_id');

        // Too early: the image shows for 20 seconds.
        $this->travel(5)->seconds();
        $this->postJson("/api/mobile/v1/watch-earn/views/{$view}/complete", [], $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'watch_earn_too_fast');

        $this->travel(20)->seconds();
        $this->postJson("/api/mobile/v1/watch-earn/views/{$view}/complete", [], $this->headers())->assertOk()->assertJsonPath('data.can_interact', true);
        $this->postJson("/api/mobile/v1/watch-earn/views/{$view}/complete", [], $this->headers())->assertOk();

        $ad->refresh();
        $this->assertSame(300, $ad->spent_minor);
        $this->assertSame(2700, $ad->reservedMinor());
        $this->assertSame(1, $ad->completed_count);
        $this->assertSame(150, $this->mainWallet($this->viewer)->reward_minor, 'the view reward is in the reward bucket');
        $this->assertSame(100, (int) FinancialEntry::query()->sum('amount_minor'), 'the platform share is booked');

        // Watched: gone from the feed, and can't be started again.
        $this->assertSame([], $this->getJson('/api/mobile/v1/watch-earn/feed', $this->headers())->json('data.ads'));
        $this->postJson("/api/mobile/v1/watch-earn/ads/{$ad->uuid}/start", [], $this->headers())->assertStatus(403);

        // The first interaction in the window earns the interaction share — once.
        $this->postJson("/api/mobile/v1/watch-earn/ads/{$ad->uuid}/reaction", ['emoji' => '❤️'], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/watch-earn/ads/{$ad->uuid}/comments", ['body' => 'جميل'], $this->headers())->assertCreated();
        $this->assertSame(200, $this->mainWallet($this->viewer)->reward_minor);
        $this->assertSame(2, Reward::query()->count());
        $this->assertSame(1, $ad->refresh()->interactions_count);
    }

    public function test_an_unclaimed_interaction_share_goes_back_to_the_budget_when_the_country_says_so(): void
    {
        WeSetting::query()->update(['unclaimed_interaction' => WeSetting::UNCLAIMED_NOT_CHARGED]);
        $ad = $this->approvedAd(budget: 600);
        $this->watch($ad);
        $this->assertSame(300, $ad->refresh()->reservedMinor());

        $this->travel(11)->minutes();
        $this->artisan('watch-earn:tick')->assertSuccessful();

        $ad->refresh();
        $this->assertSame(350, $ad->reservedMinor(), 'the 0.50 interaction share came back');
        $this->assertSame(250, $ad->spent_minor);
    }

    public function test_the_last_view_exhausts_the_ad_and_the_platform_keeps_an_unclaimed_share_by_default(): void
    {
        $ad = $this->approvedAd(budget: 300);
        $this->watch($ad);
        $this->assertSame(AdStatus::Exhausted, $ad->refresh()->status);

        $this->travel(11)->minutes();
        $this->artisan('watch-earn:tick')->assertSuccessful();
        $this->assertSame(150, (int) FinancialEntry::query()->sum('amount_minor'), '1.00 view share + 0.50 unclaimed interaction');

        // More budget brings it back.
        $this->fund(1000);
        app(AdService::class)->addBudget($ad, 600);
        $this->assertSame(AdStatus::Active, $ad->refresh()->status);
    }

    public function test_reserved_budget_is_locked_until_the_ad_stops_then_it_can_go_back(): void
    {
        $this->fund(5000);
        $ad = $this->approvedAd(budget: 3000, fund: false);

        $this->asAdvertiser();
        $this->postJson('/api/mobile/v1/watch-earn/ad-wallet/return', ['amount_minor' => 3000, 'idempotency_key' => 'r-x'], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'watch_earn_insufficient_ad_wallet');

        $this->postJson("/api/mobile/v1/watch-earn/advertiser/ads/{$ad->uuid}/stop", [], $this->headers())->assertOk()->assertJsonPath('data.status', 'stopped');
        $this->postJson('/api/mobile/v1/watch-earn/ad-wallet/return', ['amount_minor' => 5000, 'idempotency_key' => 'r-y'], $this->headers())->assertOk();
        $this->assertSame(0, AdWallet::query()->sole()->freeMinor());
    }

    public function test_city_targeted_ads_reach_only_that_city_and_come_first(): void
    {
        $everyone = $this->approvedAd(budget: 3000);
        $riyadhOnly = $this->approvedAd(budget: 3000, cities: [$this->riyadh->id]);
        $jeddahOnly = $this->approvedAd(budget: 3000, cities: [$this->jeddah->id]);

        $this->viewer->forceFill(['geo_city_id' => $this->riyadh->id, 'geo_ip' => '127.0.0.1', 'geo_at' => now()])->save();
        $this->asViewer();
        $ids = collect($this->getJson('/api/mobile/v1/watch-earn/feed', $this->headers())->assertOk()->json('data.ads'))->pluck('id')->all();

        $this->assertSame([$riyadhOnly->uuid, $everyone->uuid], $ids);
        $this->assertNotContains($jeddahOnly->uuid, $ids);
    }

    public function test_the_advertiser_never_sees_their_own_ad_and_enough_reports_suspend_it(): void
    {
        $ad = $this->approvedAd(budget: 3000);
        $this->asAdvertiser();
        $this->assertSame([], $this->getJson('/api/mobile/v1/watch-earn/feed', $this->headers())->json('data.ads'));

        $other = User::create(['name' => 'Other', 'phone' => '+966500000003', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        foreach ([$this->viewer, $other] as $user) {
            Sanctum::actingAs($user, [], 'user_api');
            $this->postJson("/api/mobile/v1/watch-earn/ads/{$ad->uuid}/report", ['reason' => 'misleading'], $this->headers())->assertOk();
        }
        $this->postJson("/api/mobile/v1/watch-earn/ads/{$ad->uuid}/report", ['reason' => 'scam'], $this->headers())->assertStatus(409);

        $this->assertSame(AdStatus::Suspended, $ad->refresh()->status);
    }

    public function test_the_wizard_sends_an_ad_to_review_with_its_budget_reserved(): void
    {
        $this->fund(5000);
        $this->asAdvertiser();

        $this->postJson('/api/mobile/v1/watch-earn/advertiser/audience', ['cities' => [$this->riyadh->id]], $this->headers())->assertOk()->assertJsonPath('data.people', 0);

        $id = $this->post('/api/mobile/v1/watch-earn/advertiser/ads', [
            'media' => UploadedFile::fake()->image('ad.jpg', 600, 800),
            'title' => 'عرض اليوم',
            'starts_at' => now()->toDateTimeString(),
            'ends_at' => now()->addDays(7)->toDateTimeString(),
            'budget_minor' => 3000,
            'cities' => [$this->riyadh->id],
        ], $this->headers() + ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'pending_review')->json('data.id');

        $this->assertSame(2000, AdWallet::query()->sole()->freeMinor());
        $this->assertSame(3000, Ad::query()->where('uuid', $id)->sole()->reservedMinor());

        // Not shown before the review.
        $this->asViewer();
        $this->assertSame([], $this->getJson('/api/mobile/v1/watch-earn/feed', $this->headers())->json('data.ads'));

        // Rejected: the budget is free again.
        app(AdService::class)->reject(Ad::query()->where('uuid', $id)->sole(), 1, 'Low quality');
        $this->assertSame(5000, AdWallet::query()->sole()->freeMinor());
    }

    public function test_the_advertiser_reports_a_comment_and_the_admins_answer_removes_it(): void
    {
        $ad = $this->approvedAd(budget: 3000);
        $this->watch($ad);
        $this->asViewer();
        $commentId = $this->postJson("/api/mobile/v1/watch-earn/ads/{$ad->uuid}/comments", ['body' => 'سيئ'], $this->headers())->assertCreated()->json('data.id');

        // The advertiser can't delete it — only report it.
        $this->asAdvertiser();
        $this->deleteJson("/api/mobile/v1/watch-earn/ads/{$ad->uuid}/comments/{$commentId}", [], $this->headers())->assertNotFound();
        $this->postJson("/api/mobile/v1/watch-earn/advertiser/ads/{$ad->uuid}/comments/{$commentId}/report", ['reason' => 'offensive'], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/watch-earn/advertiser/ads/{$ad->uuid}/comments/{$commentId}/report", ['reason' => 'spam'], $this->headers())->assertStatus(409);

        // Someone else's ad: no.
        $this->asViewer();
        $this->postJson("/api/mobile/v1/watch-earn/advertiser/ads/{$ad->uuid}/comments/{$commentId}/report", ['reason' => 'offensive'], $this->headers())->assertNotFound();

        app(CommentReportService::class)->decide(CommentReport::query()->sole(), CommentReport::REMOVED, 1, 'مخالف');

        $this->assertSoftDeleted('we_comments', ['id' => $commentId]);

        // The author opens the ad from the push; someone who never saw it can't.
        $this->asViewer();
        $this->getJson("/api/mobile/v1/watch-earn/ads/{$ad->uuid}", $this->headers())->assertOk()->assertJsonPath('data.watched', true);
        Sanctum::actingAs(User::create(['name' => 'Stranger', 'phone' => '+966500000009', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]), [], 'user_api');
        $this->getJson("/api/mobile/v1/watch-earn/ads/{$ad->uuid}", $this->headers())->assertNotFound();
        $this->assertSame(0, $ad->refresh()->comments_count);
        $this->assertSame(CommentReport::REMOVED, CommentReport::query()->sole()->status);
    }

    public function test_rewards_are_spent_first_by_every_payment_in_the_app(): void
    {
        $wallets = app(WalletService::class);
        $wallet = $this->mainWallet();
        $wallets->credit($wallet, 400, WalletBucket::Reward, WalletTransactionType::Reward);
        $wallet->refresh();

        $this->assertSame(1000400, $wallet->spendableMinor());
        $this->assertSame([[WalletBucket::Reward, 400], [WalletBucket::SpendOnly, 500000], [WalletBucket::Withdrawable, 100]], $wallet->spendParts(500500));

        $wallets->debitSpendOnlyFirst($wallet, 1000, WalletTransactionType::ServicePayment, ['idempotency_key' => 'pay-1']);
        $wallets->debitSpendOnlyFirst($wallet, 1000, WalletTransactionType::ServicePayment, ['idempotency_key' => 'pay-1']);
        $wallet->refresh();
        $this->assertSame([0, 499400, 500000], [$wallet->reward_minor, $wallet->spend_only_minor, $wallet->withdrawable_minor]);
    }

    public function test_the_ad_wallet_is_topped_up_on_the_payment_screen(): void
    {
        $checkouts = app(CheckoutService::class);

        // Paid from the wallet's balance: like a fund, non-withdrawable first.
        $checkout = $checkouts->create($this->advertiser, $this->saudi, AdWalletTopupPurpose::KEY, ['amount_minor' => 2000]);
        $checkouts->payWithWallet($this->advertiser, $checkout);
        $adWallet = AdWallet::query()->sole();
        $this->assertSame([2000, 0], [$adWallet->spend_only_minor, $adWallet->withdrawable_minor]);
        $this->assertSame(0, (int) FinancialEntry::query()->sum('amount_minor'), 'no DORR revenue: it is still the advertiser\'s money');

        // Through a gateway: the top-up landed as withdrawable and goes on as withdrawable (D12) — once.
        app(AdWalletService::class)->fromCheckout($this->advertiser, $this->saudi, 1500, true, 'card-1');
        app(AdWalletService::class)->fromCheckout($this->advertiser, $this->saudi, 1500, true, 'card-1');
        $this->assertSame(1500, $adWallet->refresh()->withdrawable_minor);
        $this->assertSame(500000 - 1500, $this->mainWallet()->withdrawable_minor);
    }

    public function test_the_advertiser_exports_the_stats_as_pdf_and_excel_and_no_one_else_can(): void
    {
        $ad = $this->approvedAd(budget: 3000);
        $this->watch($ad);

        $this->asAdvertiser();
        $pdf = $this->get("/api/mobile/v1/watch-earn/advertiser/ads/{$ad->uuid}/export?format=pdf", $this->headers())->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $xlsx = $this->get("/api/mobile/v1/watch-earn/advertiser/ads/{$ad->uuid}/export?format=xlsx", $this->headers())->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->getContent(), 'an xlsx is a zip');

        $this->asViewer();
        $this->getJson("/api/mobile/v1/watch-earn/advertiser/ads/{$ad->uuid}/export?format=pdf", $this->headers())->assertNotFound();
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────────

    private function approvedAd(int $budget, array $cities = [], bool $fund = true): Ad
    {
        if ($fund) {
            $this->fund($budget);
        }
        $ad = app(AdService::class)->create($this->advertiser, $this->saudi, [
            'title' => 'Ad '.uniqid(),
            'starts_at' => now(),
            'ends_at' => now()->addDays(7),
            'budget_minor' => $budget,
            'cities' => $cities,
        ], UploadedFile::fake()->image('ad.jpg'));
        $ad->forceFill(['starts_at' => now()->subMinute()])->save();

        return app(AdService::class)->approve($ad, 1);
    }

    private function watch(Ad $ad): void
    {
        $views = app(ViewService::class);
        $view = $views->start($this->viewer, $this->saudi, $ad);
        $this->travel(21)->seconds();
        $views->complete($this->viewer, $view);
    }

    private function fund(int $amount, ?string $key = null): void
    {
        $this->asAdvertiser();
        $this->postJson('/api/mobile/v1/watch-earn/ad-wallet/fund', ['amount_minor' => $amount, 'idempotency_key' => $key ?? uniqid('f-', true)], $this->headers() + ['X-Wallet-Pin' => '1234'])->assertOk();
    }

    private function mainWallet(?User $user = null): Wallet
    {
        return Wallet::query()->where('owner_type', 'user')->where('owner_id', ($user ?? $this->advertiser)->id)->sole();
    }

    private function asAdvertiser(): void
    {
        Sanctum::actingAs($this->advertiser, [], 'user_api');
    }

    private function asViewer(): void
    {
        Sanctum::actingAs($this->viewer, [], 'user_api');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }
}
