<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelancePlan;
use Modules\Freelance\Models\FreelancePlanPrice;
use Modules\Freelance\Models\FreelancePolicy;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceSubscription;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Offers\FreelancerOffers;
use Modules\Freelance\Services\Plans\FreelancePlanDefaults;
use Modules\Freelance\Services\Plans\FreelanceSubscriptions;
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
 * FL-L01: freelancer plans. The default prices per country, a first trial, buying / upgrading / downgrading / cancelling, renewals with the
 * grace days, what a plan changes (the monthly free offers and a lower commission, kept in the agreement), and the admin's desk.
 */
class FreelancePlansTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private Country $saudi;

    private User $client;

    private Freelancer $sara;

    private FreelanceProject $project;

    private FreelancePlan $free;

    private FreelancePlan $plus;

    private FreelancePlan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'free_offer_quota', 'commission', 'subscriptions'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();

        $web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->project = FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);

        $this->free = FreelancePlan::query()->create(['slug' => 'free', 'rank' => 0, 'name' => ['ar' => 'مجانية', 'en' => 'Free']]);
        $this->plus = FreelancePlan::query()->create(['slug' => 'plus', 'rank' => 1, 'name' => ['ar' => 'بلس', 'en' => 'Plus'], 'offers_monthly' => 40, 'commission_bp' => 800, 'trial_days' => 14]);
        $this->pro = FreelancePlan::query()->create(['slug' => 'pro', 'rank' => 2, 'name' => ['ar' => 'برو', 'en' => 'Pro'], 'offers_unlimited' => true, 'commission_bp' => 600, 'trial_days' => 14]);
        foreach ([[$this->plus, 3400, 32500], [$this->pro, 9000, 86500]] as [$plan, $monthly, $yearly]) {
            FreelancePlanPrice::query()->create(['plan_id' => $plan->id, 'country_id' => $this->saudi->id, 'period' => 'monthly', 'price_minor' => $monthly]);
            FreelancePlanPrice::query()->create(['plan_id' => $plan->id, 'country_id' => $this->saudi->id, 'period' => 'yearly', 'price_minor' => $yearly]);
        }
    }

    private function asSara(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->sara->refresh(), [], 'freelance_api');
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client->refresh(), [], 'user_api');
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

    private function take(string $plan, string $period = 'monthly')
    {
        $this->asSara();

        return $this->postJson(self::API.'/subscription', ['plan' => $plan, 'period' => $period]);
    }

    /** The one trial is used: an old, ended subscription is on record. */
    private function trialUsed(): void
    {
        FreelanceSubscription::query()->create([
            'freelancer_id' => $this->sara->id, 'plan_id' => $this->plus->id, 'country_id' => $this->saudi->id, 'currency_code' => 'SAR', 'period' => 'monthly',
            'price_minor' => 3400, 'status' => 'expired', 'auto_renew' => false, 'started_at' => now()->subMonths(3), 'period_start' => now()->subMonths(3),
            'period_end' => now()->subMonths(2), 'ended_at' => now()->subMonths(2),
        ]);
    }

    private function live(): ?FreelanceSubscription
    {
        return FreelanceSubscription::query()->live()->where('freelancer_id', $this->sara->id)->first();
    }

    private function income(): int
    {
        return (int) FinancialEntry::query()->whereHas('category', fn ($q) => $q->where('slug', 'freelance_subscriptions'))->sum('amount_minor');
    }

    // ------------------------------------------------------------------ the defaults

    /** One price for the whole world (`freelance.money`): only the money country's row gets prices (Saudi in these tests). */
    public function test_the_seeder_prices_the_money_country_only_and_never_overwrites(): void
    {
        FreelancePlanPrice::query()->delete();
        FreelancePlan::query()->delete();
        Currency::query()->where('code', 'SAR')->update(['exchange_rate' => 0.0766, 'decimal_places' => 2]);
        Currency::create(['code' => 'USD', 'symbol' => '$', 'exchange_rate' => 0.02, 'decimal_places' => 2]);
        $jpy = Currency::create(['code' => 'JPY', 'symbol' => 'Y', 'exchange_rate' => 0.01, 'decimal_places' => 0]);
        $japan = Country::create(['code' => 'JP', 'dial_code' => '+81', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'jp'])->id, 'currency_id' => $jpy->id]);

        $added = app(FreelancePlanDefaults::class)->seed();
        $this->assertSame(3, $added['plans']);
        $this->assertSame(4, $added['prices']);

        $plus = FreelancePlan::query()->where('slug', 'plus')->firstOrFail();
        $price = fn (Country $c, string $period, FreelancePlan $p) => FreelancePlanPrice::query()->where(['plan_id' => $p->id, 'country_id' => $c->id, 'period' => $period])->value('price_minor');
        // 9 USD in SAR (3.83 each → 34), a year = 12 × 34 less 20% = 326.4 → 325.
        $this->assertSame(3400, $price($this->saudi, 'monthly', $plus));
        $this->assertSame(32500, $price($this->saudi, 'yearly', $plus));
        // Any other country gets no price of its own: it pays the world's.
        $this->assertNull($price($japan, 'monthly', $plus));
        $this->assertSame(0, FreelancePlan::query()->where('slug', 'free')->firstOrFail()->prices()->count());
        $this->assertSame(['offers_monthly' => 40, 'commission_bp' => 800, 'trial_days' => 14], $plus->only(['offers_monthly', 'commission_bp', 'trial_days']));

        // An admin's price stays; a second run adds nothing.
        FreelancePlanPrice::query()->where(['plan_id' => $plus->id, 'country_id' => $this->saudi->id, 'period' => 'monthly'])->update(['price_minor' => 4900]);
        $again = app(FreelancePlanDefaults::class)->seed();
        $this->assertSame(['plans' => 0, 'prices' => 0], $again);
        $this->assertSame(4900, $price($this->saudi, 'monthly', $plus));
    }

    // ------------------------------------------------------------------ taking a plan

    public function test_the_catalog_shows_the_plans_of_my_country_with_prices_and_the_trial(): void
    {
        $this->asSara();
        $res = $this->getJson(self::API.'/plans')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.currency', 'SAR');
        $plans = collect($res->json('data.plans'))->keyBy('slug');
        $this->assertSame(['free', 'plus', 'pro'], $plans->keys()->all());
        $this->assertTrue($plans['free']['current']);
        $this->assertSame(['monthly' => 3400, 'yearly' => 32500], $plans['plus']['prices']);
        $this->assertSame(3400 * 12 - 32500, $plans['plus']['yearly_saving_minor']);
        $this->assertSame(14, $plans['plus']['trial_days']);
        $this->assertTrue($plans['pro']['benefits']['offers_unlimited']);
        $this->assertSame(40, $plans['plus']['benefits']['offers_monthly']);

        // A plan with no price in this country is not offered.
        FreelancePlanPrice::query()->where('plan_id', $this->pro->id)->delete();
        $this->assertSame(['free', 'plus'], array_column($this->getJson(self::API.'/plans')->json('data.plans'), 'slug'));
    }

    public function test_the_first_plan_is_a_free_trial_and_the_next_is_paid_from_the_wallet_spend_only_first(): void
    {
        $this->take('plus')->assertOk()->assertJsonPath('data.subscription.status', 'trialing')->assertJsonPath('data.subscription.plan.slug', 'plus');
        $this->assertSame(0, (int) $this->wallet()->withdrawable_minor);
        $this->assertSame(0, $this->income());
        $this->assertTrue(now()->addDays(14)->isSameDay($this->live()->period_end));
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.subscription.started')->count());

        // The same plan again: nothing to change. Cancelling then waiting the trial out ends it.
        $this->take('plus')->assertStatus(409);
        $this->postJson(self::API.'/subscription/cancel')->assertOk()->assertJsonPath('data.subscription.auto_renew', false);
        $this->travel(15)->days();
        $this->assertSame(['renewed' => 0, 'failed' => 0, 'ended' => 1, 'reminded' => 0], app(FreelanceSubscriptions::class)->run());
        $this->assertNull($this->live());
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.subscription.expired')->count());

        // Now it is paid: 1,000 spend-only and 2,400 withdrawable.
        $this->topUp(5000, 1000);
        $this->take('plus')->assertOk()->assertJsonPath('data.subscription.status', 'active');
        $wallet = $this->wallet();
        $this->assertSame(0, (int) $wallet->spend_only_minor);
        $this->assertSame(5000 - 2400, (int) $wallet->withdrawable_minor);
        $this->assertSame(3400, $this->income());
        $invoice = $this->getJson(self::API.'/subscription')->assertOk()->json('data.invoices.0');
        $this->assertSame(['purchase', 'paid', 3400, 0], [$invoice['kind'], $invoice['status'], $invoice['amount_minor'], $invoice['credit_minor']]);
    }

    public function test_not_enough_in_the_wallet_says_so_and_changes_nothing(): void
    {
        $this->trialUsed();
        $this->topUp(1000);
        $this->take('plus')->assertStatus(422)->assertJsonPath('error_code', 'freelance_subscription_insufficient')->assertJsonPath('data.shortfall_minor', 2400);
        $this->assertNull($this->live());
        $this->assertSame(1000, (int) $this->wallet()->withdrawable_minor);
        $this->assertSame(0, $this->income());
    }

    public function test_a_plan_that_does_not_exist_or_has_no_price_is_refused(): void
    {
        $this->take('gold')->assertStatus(422)->assertJsonPath('error_code', 'freelance_plan_unavailable');
        $this->take('free')->assertStatus(422)->assertJsonPath('error_code', 'freelance_plan_unavailable');
        $this->take('plus', 'weekly')->assertStatus(422);
    }

    // ------------------------------------------------------------------ changing

    public function test_an_upgrade_takes_the_unused_time_off_and_a_downgrade_waits_for_the_end(): void
    {
        $this->trialUsed();
        $this->topUp(50000);
        $this->take('plus')->assertOk();
        $sub = $this->live();

        // Ten days in: Pro now, the unused part of the 34 already paid is taken off.
        $this->travel(10)->days();
        $this->take('pro')->assertOk()->assertJsonPath('data.subscription.plan.slug', 'pro');
        $total = $sub->period_end->getTimestamp() - $sub->period_start->getTimestamp();
        $left = $sub->period_end->getTimestamp() - now()->getTimestamp();
        $credit = intdiv(3400 * $left, $total);
        $invoice = $this->getJson(self::API.'/subscription')->json('data.invoices.0');
        $this->assertSame(['upgrade', 9000 - $credit, $credit, 9000], [$invoice['kind'], $invoice['amount_minor'], $invoice['credit_minor'], $invoice['price_minor']]);
        $this->assertGreaterThan(0, $credit);
        $this->assertSame(3400 + 9000 - $credit, $this->income());
        $this->assertTrue($this->live()->period_end->gt($sub->period_end));

        // Back to Plus: it waits; Pro stays until the end, then Plus is charged.
        $this->take('plus')->assertOk()->assertJsonPath('data.subscription.plan.slug', 'pro')->assertJsonPath('data.subscription.pending.plan.slug', 'plus');
        // Changing your mind takes it back.
        $this->take('pro')->assertOk()->assertJsonPath('data.subscription.pending', null);
        $this->take('plus')->assertOk();

        $this->travelTo($this->live()->period_end->copy()->addMinute());
        $this->assertSame(1, app(FreelanceSubscriptions::class)->run()['renewed']);
        $live = $this->live();
        $this->assertSame('plus', $live->plan->slug);
        $this->assertNull($live->pending_plan_id);
        $this->assertSame(3400, $live->price_minor);
        $this->assertSame('renewal', $live->invoices()->latest('id')->first()->kind);
    }

    public function test_cancelling_keeps_the_plan_to_the_end_and_resuming_turns_renewal_back_on(): void
    {
        $this->trialUsed();
        $this->topUp(50000);
        $this->take('plus')->assertOk();
        $this->postJson(self::API.'/subscription/resume')->assertStatus(409);
        $this->postJson(self::API.'/subscription/cancel')->assertOk()->assertJsonPath('data.subscription.auto_renew', false)->assertJsonPath('data.subscription.active', true);
        $this->assertSame('plus', app(\Modules\Freelance\Services\Plans\FreelancePlans::class)->planFor($this->sara)->slug);

        $this->postJson(self::API.'/subscription/resume')->assertOk()->assertJsonPath('data.subscription.auto_renew', true);
        $this->postJson(self::API.'/subscription/cancel')->assertOk();

        // The period ends with renewal off: it ends, and nothing more is charged.
        $before = (int) $this->wallet()->withdrawable_minor;
        $this->travelTo($this->live()->period_end->copy()->addMinute());
        $this->assertSame(1, app(FreelanceSubscriptions::class)->run()['ended']);
        $this->assertSame($before, (int) $this->wallet()->withdrawable_minor);
        $this->assertSame('free', app(\Modules\Freelance\Services\Plans\FreelancePlans::class)->planFor($this->sara)->slug);
    }

    // ------------------------------------------------------------------ renewals

    public function test_a_renewal_is_reminded_then_charged_at_the_price_of_the_day(): void
    {
        $this->trialUsed();
        $this->topUp(50000);
        $this->take('plus')->assertOk();
        $this->travelTo($this->live()->period_end->copy()->subDays(2));
        $this->assertSame(1, app(FreelanceSubscriptions::class)->run()['reminded']);
        $this->assertSame(0, app(FreelanceSubscriptions::class)->run()['reminded'], 'told once');
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.subscription.renewing')->count());

        // The admin raised the price meanwhile.
        FreelancePlanPrice::query()->where(['plan_id' => $this->plus->id, 'country_id' => $this->saudi->id, 'period' => 'monthly'])->update(['price_minor' => 4000]);
        $end = $this->live()->period_end->copy();
        $this->travelTo($end->copy()->addMinute());
        $this->assertSame(1, app(FreelanceSubscriptions::class)->run()['renewed']);
        $live = $this->live();
        $this->assertSame(4000, $live->price_minor);
        $this->assertTrue($live->period_start->equalTo($end), 'the new period starts where the old one ended');
        $this->assertSame(3400 + 4000, $this->income());
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.subscription.renewed')->count());
    }

    public function test_a_failed_renewal_keeps_the_plan_through_the_grace_days_then_ends_it_and_paying_in_time_saves_it(): void
    {
        $this->trialUsed();
        $this->topUp(3400);
        $this->take('plus')->assertOk();
        $end = $this->live()->period_end->copy();

        $this->travelTo($end->copy()->addMinute());
        $this->assertSame(1, app(FreelanceSubscriptions::class)->run()['failed']);
        $this->assertSame('past_due', $this->live()->status);
        $this->assertSame('plus', app(\Modules\Freelance\Services\Plans\FreelancePlans::class)->planFor($this->sara)->slug, 'the plan stays in the grace days');
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.subscription.renewal_failed')->count());
        $this->assertSame('failed', $this->live()->invoices()->latest('id')->first()->status);

        // The same day: not tried again, not told again. A change is refused until it is paid.
        $this->travelTo($end->copy()->addHours(5));
        app(FreelanceSubscriptions::class)->run();
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.subscription.renewal_failed')->count());
        $this->take('pro')->assertStatus(409)->assertJsonPath('error_code', 'freelance_subscription_past_due');
        $this->postJson(self::API.'/subscription/renew')->assertStatus(422);

        // Topped up inside the grace days: renewed now.
        $this->topUp(3400);
        $this->postJson(self::API.'/subscription/renew')->assertOk()->assertJsonPath('data.subscription.status', 'active');
        $this->assertSame(3400 * 2, $this->income());

        // Another month, another failure — this time nothing comes: it ends after the grace days.
        $end = $this->live()->period_end->copy();
        $this->travelTo($end->copy()->addMinute());
        app(FreelanceSubscriptions::class)->run();
        $this->assertSame('past_due', $this->live()->status);
        $this->travelTo($end->copy()->addDays(4));
        $this->assertSame(1, app(FreelanceSubscriptions::class)->run()['ended']);
        $this->assertNull($this->live());
        $this->assertSame('free', app(\Modules\Freelance\Services\Plans\FreelancePlans::class)->planFor($this->sara)->slug);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.subscription.expired')->count());
    }

    // ------------------------------------------------------------------ what a plan changes

    public function test_a_plan_raises_the_monthly_free_offers_or_lifts_the_limit(): void
    {
        $this->asSara();
        $this->getJson(self::API.'/subscription')->assertOk()->assertJsonPath('data.quota.limit', 10)->assertJsonPath('data.quota.plan', 'free');

        $this->take('plus')->assertOk()->assertJsonPath('data.quota.limit', 40)->assertJsonPath('data.quota.plan', 'plus');
        $this->take('pro')->assertOk()->assertJsonPath('data.quota.limit', null)->assertJsonPath('data.quota.left', null)->assertJsonPath('data.quota.enabled', true);

        // A country that already gives more than the plan keeps its number.
        FreelancePolicy::query()->create(['country_id' => $this->saudi->id, 'specialty_id' => null, 'data' => ['offers.free_monthly_quota' => 60], 'status' => true]);
        $this->take('plus')->assertOk();
        $this->assertSame(60, app(FreelancerOffers::class)->quota($this->sara)['limit']);
    }

    public function test_a_plan_lowers_the_commission_and_never_raises_it(): void
    {
        $offers = app(FreelancerOffers::class);
        $this->assertSame(10000, $offers->preview($this->project, 100000, $this->sara)['commission_minor']);

        $this->take('plus')->assertOk();
        $this->assertSame(8000, $offers->preview($this->project, 100000, $this->sara)['commission_minor']);
        $this->take('pro')->assertOk();
        $this->assertSame(94000, $offers->preview($this->project, 100000, $this->sara)['net_minor']);
        // Without a freelancer (the client's side) nothing changes.
        $this->assertSame(10000, $offers->preview($this->project, 100000)['commission_minor']);

        // A country with a commission lower than the plan's keeps its own.
        FreelancePolicy::query()->create(['country_id' => $this->saudi->id, 'specialty_id' => null, 'data' => ['commission.value' => 500], 'status' => true]);
        $this->assertSame(5000, $offers->preview($this->project, 100000, $this->sara)['commission_minor']);
    }

    public function test_the_agreement_keeps_the_commission_of_the_plan_it_was_made_under(): void
    {
        $this->take('plus')->assertOk();
        $this->postJson(self::API."/projects/{$this->project->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.',
        ])->assertCreated();
        $offer = FreelanceOffer::query()->where('freelancer_id', $this->sara->id)->firstOrFail();
        $this->assertSame(28000, $offer->commission_minor);

        $this->asClient();
        $this->getJson("/api/mobile/v1/freelance/projects/{$this->project->uuid}/offers")->assertOk();
        $this->postJson("/api/mobile/v1/freelance/projects/{$this->project->uuid}/offers/{$offer->uuid}/choose")->assertOk();
        $agreement = FreelanceAgreement::query()->where('freelancer_id', $this->sara->id)->firstOrFail();
        $this->assertSame(800, $agreement->policy['commission.value']);
        $this->assertSame(28000, $agreement->money['commission_minor']);

        // The plan ends; the agreement still says 8%.
        $this->live()->update(['status' => 'expired', 'auto_renew' => false]);
        $this->assertSame(800, $agreement->refresh()->policy['commission.value']);
        $this->assertSame(10000, app(FreelancerOffers::class)->preview($this->project, 100000, $this->sara)['commission_minor']);
    }

    public function test_with_the_switch_off_nothing_is_sold_and_every_freelancer_is_on_free(): void
    {
        $this->take('pro')->assertOk();
        FreelanceFeatureRule::query()->where(['feature' => 'subscriptions', 'country_id' => $this->saudi->id])->update(['enabled' => false]);
        FreelanceFeatures::forget();

        $this->asSara();
        $this->getJson(self::API.'/plans')->assertStatus(403)->assertJsonPath('error_code', 'freelance_feature_off');
        $this->assertSame('free', app(\Modules\Freelance\Services\Plans\FreelancePlans::class)->planFor($this->sara)->slug);
        $this->assertSame(10, app(FreelancerOffers::class)->quota($this->sara)['limit']);
        $this->assertSame(10000, app(FreelancerOffers::class)->preview($this->project, 100000, $this->sara)['commission_minor']);
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

    public function test_staff_see_the_plans_edit_what_they_give_and_set_prices_per_country(): void
    {
        $this->topUp(50000);
        $this->take('plus')->assertOk();

        $this->staff('freelance-plans.view', 'freelance-plans.update');
        $res = $this->getJson('/api/admin/v1/freelance-plans')->assertOk();
        $this->assertSame(['free', 'plus', 'pro'], array_column($res->json('data.plans'), 'slug'));
        $this->assertSame(1, collect($res->json('data.plans'))->firstWhere('slug', 'plus')['subscribers']);

        // What Plus gives: more offers, a lower commission. Free is fixed.
        $this->putJson('/api/admin/v1/freelance-plans/'.$this->plus->id, ['offers_monthly' => 80, 'commission_bp' => 700, 'trial_days' => 7])->assertOk()->assertJsonPath('data.offers_monthly', 80);
        $this->assertSame(['offers_monthly' => 80, 'commission_bp' => 700, 'trial_days' => 7], $this->plus->refresh()->only(['offers_monthly', 'commission_bp', 'trial_days']));
        $this->putJson('/api/admin/v1/freelance-plans/'.$this->free->id, ['commission_bp' => 100, 'offers_monthly' => 5])->assertOk();
        $this->assertNull($this->free->refresh()->commission_bp);
        $this->putJson('/api/admin/v1/freelance-plans/'.$this->plus->id, ['commission_bp' => 20000])->assertStatus(422);

        // Prices: every country appears; set Saudi's, and a year must cost less than twelve months.
        $list = $this->getJson('/api/admin/v1/freelance-plans/'.$this->plus->id.'/prices')->assertOk();
        $row = collect($list->json('data'))->firstWhere('code', 'SA');
        $this->assertSame([3400, 32500, 'SAR'], [$row['monthly']['price_minor'], $row['yearly']['price_minor'], $row['currency']]);
        $this->putJson('/api/admin/v1/freelance-plans/'.$this->plus->id.'/prices/'.$this->saudi->id, ['monthly_minor' => 3000, 'yearly_minor' => 40000])->assertStatus(422)->assertJsonPath('error_code', 'freelance_plan_yearly_too_high');
        $this->putJson('/api/admin/v1/freelance-plans/'.$this->plus->id.'/prices/'.$this->saudi->id, ['monthly_minor' => 3000, 'yearly_minor' => 28000])->assertOk();
        $this->assertSame(28000, FreelancePlanPrice::query()->where(['plan_id' => $this->plus->id, 'country_id' => $this->saudi->id, 'period' => 'yearly'])->value('price_minor'));
        $this->putJson('/api/admin/v1/freelance-plans/'.$this->free->id.'/prices/'.$this->saudi->id, ['monthly_minor' => 100, 'yearly_minor' => 900])->assertStatus(422);

        // Who is subscribed, and what the plans brought in.
        $subs = $this->getJson('/api/admin/v1/freelance-plans/subscriptions')->assertOk();
        $this->assertSame(['Sara'], array_column(array_column($subs->json('data'), 'freelancer'), 'name'));
        $this->assertSame(1, $subs->json('pagination.counts.trialing'));
        $this->assertSame([], $this->getJson('/api/admin/v1/freelance-plans/subscriptions?status=expired')->json('data'));
        $this->assertSame([], $this->getJson('/api/admin/v1/freelance-plans')->json('data.revenue_30d'), 'a trial brings in nothing');
    }

    public function test_the_desk_needs_its_permission(): void
    {
        $this->staff('freelance-risk.view');
        $this->getJson('/api/admin/v1/freelance-plans')->assertForbidden();
        $this->staff('freelance-plans.view');
        $this->getJson('/api/admin/v1/freelance-plans')->assertOk();
        $this->putJson('/api/admin/v1/freelance-plans/'.$this->plus->id, ['trial_days' => 3])->assertForbidden();
    }
}
