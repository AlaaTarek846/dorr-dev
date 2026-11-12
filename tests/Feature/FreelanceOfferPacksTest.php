<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceOfferCredit;
use Modules\Freelance\Models\FreelanceOfferPack;
use Modules\Freelance\Models\FreelanceOfferPackPrice;
use Modules\Freelance\Models\FreelancePlan;
use Modules\Freelance\Models\FreelancePolicy;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceSubscription;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Offers\FreelancerOffers;
use Modules\Freelance\Services\Plans\FreelanceOfferPackDefaults;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\FinancialEntry;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-L01 phase 2: extra offers for sale. The default packs per country, buying one from the wallet, the bought offers being spent after the
 * month's free ones (the batch that ends first, first), being given back when an offer is taken back before the client saw it, ending
 * after their days, and the admin's desk.
 */
class FreelanceOfferPacksTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private Country $saudi;

    private FreelanceSpecialty $web;

    private User $client;

    private Freelancer $sara;

    private FreelanceOfferPack $small;

    private FreelanceOfferPack $medium;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'free_offer_quota', 'commission', 'subscriptions', 'offer_packs'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        // One free offer a month, so the second one needs a pack.
        FreelancePolicy::query()->create(['country_id' => $this->saudi->id, 'specialty_id' => null, 'data' => ['offers.free_monthly_quota' => 1], 'status' => true]);
        FreelanceFeatures::forget();
        FreelancePolicies::forget();

        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);

        $this->small = FreelanceOfferPack::query()->create(['slug' => 'small', 'name' => ['ar' => 'صغيرة', 'en' => 'Small'], 'offers' => 2, 'sort_order' => 1]);
        $this->medium = FreelanceOfferPack::query()->create(['slug' => 'medium', 'name' => ['ar' => 'متوسطة', 'en' => 'Medium'], 'offers' => 5, 'sort_order' => 2]);
        FreelanceOfferPackPrice::query()->create(['pack_id' => $this->small->id, 'country_id' => $this->saudi->id, 'price_minor' => 1000]);
        FreelanceOfferPackPrice::query()->create(['pack_id' => $this->medium->id, 'country_id' => $this->saudi->id, 'price_minor' => 2200]);
    }

    private function project(string $title): FreelanceProject
    {
        return FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => $title,
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
    }

    private function asSara(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->sara->refresh(), [], 'freelance_api');
    }

    private function wallet()
    {
        return app(WalletService::class)->firstOrCreateWallet($this->sara, $this->saudi)->fresh();
    }

    private function topUp(int $withdrawable, int $spendOnly = 0): void
    {
        $w = app(WalletService::class);
        $wallet = $w->firstOrCreateWallet($this->sara, $this->saudi);
        if ($withdrawable > 0) {
            $w->credit($wallet, $withdrawable, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        }
        if ($spendOnly > 0) {
            $w->credit($wallet, $spendOnly, WalletBucket::SpendOnly, WalletTransactionType::Topup);
        }
    }

    private function buy(string $slug, ?string $requestId = null)
    {
        $this->asSara();

        return $this->postJson(self::API."/offer-packs/{$slug}/buy", $requestId === null ? [] : ['request_id' => $requestId]);
    }

    private function send(FreelanceProject $project)
    {
        $this->asSara();

        return $this->postJson(self::API."/projects/{$project->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.',
        ]);
    }

    private function quota(): array
    {
        return app(FreelancerOffers::class)->quota($this->sara->refresh());
    }

    private function income(): int
    {
        return (int) FinancialEntry::query()->whereHas('category', fn ($q) => $q->where('slug', 'freelance_offer_packs'))->sum('amount_minor');
    }

    private function pro(): void
    {
        $pro = FreelancePlan::query()->create(['slug' => 'pro', 'rank' => 2, 'name' => ['ar' => 'برو', 'en' => 'Pro'], 'offers_unlimited' => true, 'trial_days' => 0]);
        FreelanceSubscription::query()->create([
            'freelancer_id' => $this->sara->id, 'plan_id' => $pro->id, 'country_id' => $this->saudi->id, 'currency_code' => 'SAR', 'period' => 'monthly', 'price_minor' => 9000,
            'status' => 'active', 'auto_renew' => true, 'started_at' => now(), 'period_start' => now(), 'period_end' => now()->addMonth(),
        ]);
    }

    // ------------------------------------------------------------------ the defaults

    public function test_the_seeder_prices_every_pack_in_every_country_and_never_overwrites(): void
    {
        FreelanceOfferPackPrice::query()->delete();
        FreelanceOfferPack::query()->delete();
        Currency::query()->where('code', 'SAR')->update(['exchange_rate' => 0.0766, 'decimal_places' => 2]);
        Currency::create(['code' => 'USD', 'symbol' => '$', 'exchange_rate' => 0.02, 'decimal_places' => 2]);

        $added = app(FreelanceOfferPackDefaults::class)->seed();
        $this->assertSame(['packs' => 3, 'prices' => 3], $added);
        $this->assertSame([10, 25, 50], FreelanceOfferPack::query()->orderBy('sort_order')->pluck('offers')->all());
        // 3 USD in SAR (3.83 each) = 11.49, to the nearest whole riyal under 100: 11.
        $small = FreelanceOfferPack::query()->where('slug', 'small')->firstOrFail();
        $this->assertSame(1100, FreelanceOfferPackPrice::query()->where(['pack_id' => $small->id, 'country_id' => $this->saudi->id])->value('price_minor'));

        FreelanceOfferPackPrice::query()->where('pack_id', $small->id)->update(['price_minor' => 1900]);
        $small->update(['offers' => 12]);
        $this->assertSame(['packs' => 0, 'prices' => 0], app(FreelanceOfferPackDefaults::class)->seed());
        $this->assertSame(1900, FreelanceOfferPackPrice::query()->where('pack_id', $small->id)->value('price_minor'));
        $this->assertSame(12, $small->refresh()->offers);
    }

    // ------------------------------------------------------------------ the catalog

    public function test_the_catalog_lists_the_packs_of_my_country_with_what_i_have_left(): void
    {
        $this->asSara();
        $res = $this->getJson(self::API.'/offer-packs')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.currency', 'SAR')->assertJsonPath('data.unlimited', false);
        $this->assertSame([['small', 2, 1000], ['medium', 5, 2200]], array_map(fn ($p) => [$p['slug'], $p['offers'], $p['price_minor']], $res->json('data.packs')));
        $this->assertSame(0, $res->json('data.credits.available'));
        $this->assertSame(180, $res->json('data.valid_days'));
        $this->assertSame(1, $res->json('data.quota.limit'));
        $this->assertTrue($res->json('data.quota.packs'));

        // A pack with no price here, or switched off, is not offered.
        FreelanceOfferPackPrice::query()->where('pack_id', $this->medium->id)->delete();
        $this->small->update(['status' => false]);
        $this->assertSame([], $this->getJson(self::API.'/offer-packs')->json('data.packs'));
    }

    public function test_with_the_switch_off_nothing_is_sold(): void
    {
        FreelanceFeatureRule::query()->where(['feature' => 'offer_packs', 'country_id' => $this->saudi->id])->update(['enabled' => false]);
        FreelanceFeatures::forget();

        $this->asSara();
        $this->getJson(self::API.'/offer-packs')->assertStatus(403)->assertJsonPath('error_code', 'freelance_feature_off');
        $this->buy('small')->assertStatus(403);
        $this->assertFalse($this->quota()['packs']);
    }

    public function test_where_there_is_no_monthly_limit_there_is_nothing_to_buy(): void
    {
        FreelanceFeatureRule::query()->where(['feature' => 'free_offer_quota', 'country_id' => $this->saudi->id])->update(['enabled' => false]);
        FreelanceFeatures::forget();

        $this->topUp(5000);
        $this->buy('small')->assertStatus(403)->assertJsonPath('error_code', 'freelance_offer_packs_closed');
        $this->assertSame(5000, (int) $this->wallet()->withdrawable_minor);
    }

    // ------------------------------------------------------------------ buying

    public function test_buying_a_pack_takes_the_price_from_the_wallet_spend_only_first_and_adds_the_offers(): void
    {
        $this->topUp(5000, 400);
        $this->buy('small')->assertOk()->assertJsonPath('data.credits.available', 2)->assertJsonPath('data.quota.credits', 2)->assertJsonPath('data.purchases.0.quantity', 2);

        $wallet = $this->wallet();
        $this->assertSame(0, (int) $wallet->spend_only_minor);
        $this->assertSame(5000 - 600, (int) $wallet->withdrawable_minor);
        $this->assertSame(1000, $this->income());

        $credit = FreelanceOfferCredit::query()->firstOrFail();
        $this->assertSame([2, 1000, 'SAR'], [$credit->quantity, $credit->price_minor, $credit->currency_code]);
        $this->assertTrue(now()->addDays(180)->isSameDay($credit->expires_at));
        $this->assertNotNull($credit->wallet_transaction_id);
    }

    public function test_not_enough_in_the_wallet_says_so_and_buys_nothing(): void
    {
        $this->topUp(700);
        $this->buy('small')->assertStatus(422)->assertJsonPath('error_code', 'freelance_offer_pack_insufficient')->assertJsonPath('data.shortfall_minor', 300);
        $this->assertSame(0, FreelanceOfferCredit::query()->count());
        $this->assertSame(700, (int) $this->wallet()->withdrawable_minor);
        $this->assertSame(0, $this->income());
    }

    public function test_a_pack_that_does_not_exist_or_has_no_price_is_refused(): void
    {
        $this->topUp(5000);
        $this->buy('huge')->assertStatus(422)->assertJsonPath('error_code', 'freelance_offer_pack_unavailable');
        FreelanceOfferPackPrice::query()->where('pack_id', $this->small->id)->delete();
        $this->buy('small')->assertStatus(422)->assertJsonPath('error_code', 'freelance_offer_pack_unavailable');
        $this->assertSame(0, FreelanceOfferCredit::query()->count());
    }

    public function test_the_same_tap_twice_is_one_purchase(): void
    {
        $this->topUp(5000);
        $this->buy('small', 'tap-1')->assertOk();
        $this->buy('small', 'tap-1')->assertOk()->assertJsonPath('data.credits.available', 2);
        $this->assertSame(1, FreelanceOfferCredit::query()->count());
        $this->assertSame(1000, $this->income());
        $this->assertSame(4000, (int) $this->wallet()->withdrawable_minor);

        // Another tap is another purchase.
        $this->buy('small', 'tap-2')->assertOk()->assertJsonPath('data.credits.available', 4);
        $this->assertSame(3000, (int) $this->wallet()->withdrawable_minor);
    }

    public function test_a_plan_with_no_limit_does_not_need_a_pack(): void
    {
        $this->pro();
        $this->topUp(5000);
        $this->asSara();
        $this->getJson(self::API.'/offer-packs')->assertOk()->assertJsonPath('data.unlimited', true);
        $this->buy('small')->assertStatus(409)->assertJsonPath('error_code', 'freelance_offer_pack_not_needed');
        $this->assertSame(5000, (int) $this->wallet()->withdrawable_minor);
        $this->assertSame(0, $this->quota()['credits']);
        $this->assertFalse($this->quota()['packs']);
    }

    // ------------------------------------------------------------------ spending

    public function test_a_bought_offer_is_spent_after_the_free_one_and_without_a_pack_the_second_offer_is_refused(): void
    {
        $first = $this->project('First');
        $second = $this->project('Second');
        $third = $this->project('Third');

        $this->send($first)->assertCreated();
        $this->assertSame(0, $this->quota()['left']);

        // The free one is used up and nothing is bought: refused, and the app is told a pack can be bought.
        $this->send($second)->assertStatus(422)->assertJsonPath('error_code', 'freelance_offer_quota_exhausted');
        $this->assertTrue($this->quota()['packs']);

        $this->topUp(5000);
        $this->buy('small')->assertOk();
        $this->send($second)->assertCreated();
        $paid = FreelanceOffer::query()->where('project_id', $second->id)->firstOrFail();
        $this->assertFalse($paid->free);
        $this->assertNotNull($paid->credit_id);
        $this->assertTrue(FreelanceOffer::query()->where('project_id', $first->id)->firstOrFail()->free);
        $this->assertSame(1, $this->quota()['credits']);

        $this->send($third)->assertCreated();
        $this->assertSame(0, $this->quota()['credits']);
        $this->send($this->project('Fourth'))->assertStatus(422)->assertJsonPath('error_code', 'freelance_offer_quota_exhausted');
    }

    public function test_taking_a_bought_offer_back_before_the_client_saw_it_gives_it_back_but_not_after(): void
    {
        $this->topUp(5000);
        $this->buy('small')->assertOk();
        $this->send($this->project('Free one'))->assertCreated();
        $a = $this->project('A');
        $b = $this->project('B');
        $this->send($a)->assertCreated();
        $this->send($b)->assertCreated();
        $this->assertSame(0, $this->quota()['credits']);

        // Withdrawn unseen: the place comes back.
        $this->asSara();
        $this->postJson(self::API.'/offers/'.FreelanceOffer::query()->where('project_id', $a->id)->value('uuid').'/withdraw')->assertOk();
        $this->assertSame(1, $this->quota()['credits']);

        // Seen by the client first: it stays spent.
        FreelanceOffer::query()->where('project_id', $b->id)->update(['viewed_at' => now()]);
        $this->asSara();
        $this->postJson(self::API.'/offers/'.FreelanceOffer::query()->where('project_id', $b->id)->value('uuid').'/withdraw')->assertOk();
        $this->assertSame(1, $this->quota()['credits']);
    }

    public function test_the_batch_that_ends_first_is_spent_first_and_an_ended_batch_is_gone(): void
    {
        $this->topUp(10000);
        $this->buy('medium')->assertOk();              // 5 offers, 180 days
        $this->travel(100)->days();
        $this->buy('small')->assertOk();               // 2 offers, ends 100 days after the first one
        $batches = FreelanceOfferCredit::query()->orderBy('id')->get();

        $this->send($this->project('Free one'))->assertCreated();
        $this->send($this->project('Paid one'))->assertCreated();
        $this->assertSame($batches[0]->id, FreelanceOffer::query()->whereNotNull('credit_id')->firstOrFail()->credit_id, 'the older batch ends first');
        $this->assertSame(6, $this->quota()['credits']);

        // 85 days later the first batch has ended (4 of its offers were still unspent); the second has 2.
        $this->travel(85)->days();
        $this->assertSame(2, $this->quota()['credits']);
        $this->asSara();
        $summary = $this->getJson(self::API.'/offer-packs')->json('data.credits');
        $this->assertSame(2, $summary['available']);
        $this->assertCount(1, $summary['batches']);
    }

    public function test_the_free_offers_come_back_each_month_while_bought_ones_stay(): void
    {
        $this->topUp(5000);
        $this->buy('small')->assertOk();
        $this->send($this->project('Free one'))->assertCreated();
        $this->send($this->project('Paid one'))->assertCreated();
        $this->assertSame([0, 1], [$this->quota()['left'], $this->quota()['credits']]);

        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addDay());
        $this->assertSame([1, 1], [$this->quota()['left'], $this->quota()['credits']]);
    }

    // ------------------------------------------------------------------ the admin desk

    private function staff(string ...$permissions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
            $admin->givePermissionTo($permission);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    public function test_staff_edit_the_packs_set_prices_per_country_and_see_who_bought(): void
    {
        $this->topUp(5000);
        $this->buy('small')->assertOk();

        $this->staff('freelance-plans.view', 'freelance-plans.update');
        $res = $this->getJson('/api/admin/v1/freelance-plans/packs')->assertOk();
        $this->assertSame(['small', 'medium'], array_column($res->json('data.packs'), 'slug'));
        $this->assertSame(1, $res->json('data.packs.0.sold_30d'));
        $this->assertSame([['currency' => 'SAR', 'decimals' => 2, 'total_minor' => 1000, 'purchases' => 1]], $res->json('data.revenue_30d'));

        $this->putJson('/api/admin/v1/freelance-plans/packs/'.$this->small->id, ['offers' => 3, 'name' => ['ar' => 'صغيرة جدًا', 'en' => 'Tiny']])->assertOk()->assertJsonPath('data.offers', 3);
        $this->putJson('/api/admin/v1/freelance-plans/packs/'.$this->small->id, ['offers' => 0])->assertStatus(422);
        // A purchase already made keeps what it bought.
        $this->assertSame(2, FreelanceOfferCredit::query()->firstOrFail()->quantity);

        $list = $this->getJson('/api/admin/v1/freelance-plans/packs/'.$this->small->id.'/prices')->assertOk();
        $row = collect($list->json('data'))->firstWhere('code', 'SA');
        $this->assertSame([1000, 'SAR'], [$row['price']['price_minor'], $row['currency']]);
        $this->putJson('/api/admin/v1/freelance-plans/packs/'.$this->small->id.'/prices/'.$this->saudi->id, ['price_minor' => 1400])->assertOk();
        $this->assertSame(1400, FreelanceOfferPackPrice::query()->where(['pack_id' => $this->small->id, 'country_id' => $this->saudi->id])->value('price_minor'));
        $this->putJson('/api/admin/v1/freelance-plans/packs/'.$this->small->id.'/prices/'.$this->saudi->id, ['price_minor' => 0])->assertStatus(422);

        $bought = $this->getJson('/api/admin/v1/freelance-plans/pack-purchases')->assertOk();
        $this->assertSame(['Sara'], array_column(array_column($bought->json('data'), 'freelancer'), 'name'));
        $this->assertSame([2, 2], [$bought->json('data.0.quantity'), $bought->json('data.0.remaining')]);
        $this->assertSame([], $this->getJson('/api/admin/v1/freelance-plans/pack-purchases?search=nobody')->json('data'));
    }

    public function test_filling_the_prices_adds_the_packs_too_and_the_desk_needs_its_permission(): void
    {
        $this->staff('freelance-risk.view');
        $this->getJson('/api/admin/v1/freelance-plans/packs')->assertForbidden();

        $this->staff('freelance-plans.view');
        $this->getJson('/api/admin/v1/freelance-plans/packs')->assertOk();
        $this->putJson('/api/admin/v1/freelance-plans/packs/'.$this->small->id, ['offers' => 3])->assertForbidden();
        $this->postJson('/api/admin/v1/freelance-plans/fill-prices')->assertForbidden();

        $this->staff('freelance-plans.view', 'freelance-plans.update');
        $this->postJson('/api/admin/v1/freelance-plans/fill-prices')->assertOk()->assertJsonStructure(['data' => ['plans', 'prices', 'packs', 'pack_prices']]);
    }
}
