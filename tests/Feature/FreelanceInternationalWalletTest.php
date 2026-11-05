<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelancePlan;
use Modules\Freelance\Models\FreelancePlanPrice;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Plans\FreelancePlanDefaults;
use Modules\Freelance\Services\Policy\FreelanceMoney;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\WalletHold;
use Modules\Wallet\Models\WalletSetting;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * One currency for freelance work (owner, 2026-11-04 — `freelance.money`): the money sits in an international wallet on a hidden
 * `INT` row whose currency is the dollar. The freelancer has only that wallet; the client has it next to the local one, tops it up,
 * pays freelance work from it and withdraws from it, but cannot transfer from it. Policies and plan prices are one set for the world.
 */
class FreelanceInternationalWalletTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private const FREELANCER = '/api/freelance/v1/projects';

    private Country $saudi;

    private Country $egypt;

    private User $client;

    private Freelancer $sara;

    private FreelanceSpecialty $web;

    protected function setUp(): void
    {
        parent::setUp();

        // The real setting (the other freelance tests point it at their Saudi row).
        config(['freelance.money.country_code' => 'INT', 'freelance.money.currency' => 'USD', 'broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        $this->egypt = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'status' => true, 'flag_id' => Flag::create(['code' => 'eg'])->id, 'currency_id' => Currency::create(['code' => 'EGP', 'symbol' => 'EGP'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'subscriptions'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
    }

    private function money(): FreelanceMoney
    {
        return app(FreelanceMoney::class);
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client->refresh(), [], 'user_api');
    }

    private function asSara(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->sara->refresh(), [], 'freelance_api');
    }

    private function credit(Country $country, int $minor): void
    {
        $w = app(WalletService::class);
        $w->credit($w->firstOrCreateWallet($this->client, $country), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup);
    }

    public function test_the_international_row_is_hidden_in_dollars_with_transfers_off(): void
    {
        $int = $this->money()->country();

        $this->assertSame('INT', $int->code);
        $this->assertFalse((bool) $int->status, 'in no country list, never resolved for a request');
        $this->assertSame('USD', $this->money()->code());
        $this->assertFalse((bool) WalletSetting::query()->where('country_id', $int->id)->value('transfers_enabled'));
        $this->assertSame($int->id, $this->money()->country()->id, 'made once');

        $codes = collect($this->getJson('/api/general/v1/countries/dropdown')->assertOk()->json('data'))->pluck('code');
        $this->assertNotContains('INT', $codes);
        $codes = collect($this->getJson('/api/general/v1/countries/dropdown?with_international=1')->assertOk()->json('data'))->pluck('code');
        $this->assertContains('INT', $codes, 'the admin wallet pages can set its payment methods');
    }

    public function test_the_client_has_a_dollar_wallet_next_to_the_local_one_and_cannot_transfer_from_it(): void
    {
        $this->credit($this->saudi, 50000);
        $this->credit($this->money()->country(), 12000);
        $this->asClient();

        $this->getJson('/api/mobile/v1/wallet')->assertOk()
            ->assertJsonPath('data.country_code', 'SA')->assertJsonPath('data.currency_code', 'SAR')->assertJsonPath('data.total_minor', 50000)
            ->assertJsonPath('data.other_wallets', []);
        $this->getJson('/api/mobile/v1/freelance/wallet')->assertOk()
            ->assertJsonPath('data.country_code', 'INT')->assertJsonPath('data.currency_code', 'USD')->assertJsonPath('data.currency_symbol', '$')
            ->assertJsonPath('data.total_minor', 12000);

        // No transfer from it (no such routes), and the withdrawal screens are there.
        $this->postJson('/api/mobile/v1/freelance/wallet/transfers/lookup', ['mode' => 'phone', 'phone' => '500000002'])->assertNotFound();
        $this->postJson('/api/mobile/v1/freelance/wallet/transfers', [])->assertNotFound();
        $this->getJson('/api/mobile/v1/freelance/wallet/withdrawals')->assertOk();
    }

    public function test_the_freelancer_has_only_the_dollar_wallet(): void
    {
        $this->asSara();
        $this->getJson('/api/freelance/v1/wallet')->assertOk()
            ->assertJsonPath('data.country_code', 'INT')->assertJsonPath('data.currency_code', 'USD')
            ->assertJsonPath('data.withdrawable_minor', 0);
        $this->getJson('/api/freelance/v1/config')->assertOk()->assertJsonPath('data.currency.code', 'USD');
    }

    public function test_freelance_work_is_paid_from_the_dollar_wallet_not_the_local_one(): void
    {
        $project = FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => $this->money()->code(),
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $this->asSara();
        $this->postJson(self::FREELANCER."/{$project->uuid}/offers", [
            'price_minor' => 100000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.',
        ])->assertCreated();
        $offer = FreelanceOffer::query()->firstOrFail();
        $this->assertSame('USD', $offer->currency_code);

        $this->asClient();
        $this->getJson(self::CLIENT."/{$project->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$project->uuid}/offers/{$offer->uuid}/choose")->assertOk();
        $this->postJson(self::CLIENT."/{$project->uuid}/agreement/send")->assertOk();
        $this->asSara();
        $this->postJson(self::FREELANCER."/{$project->uuid}/agreement/approve")->assertOk();
        $part = FreelanceMilestone::query()->firstOrFail();

        // Money in the local wallet only: not enough, whatever it holds.
        $this->credit($this->saudi, 5000000);
        $this->asClient();
        $this->postJson(self::CLIENT."/{$project->uuid}/milestones/{$part->uuid}/fund")->assertStatus(422)
            ->assertJsonPath('error_code', 'freelance_wallet_insufficient')->assertJsonPath('data.currency', 'USD');

        $this->credit($this->money()->country(), 100000);
        $this->getJson(self::CLIENT."/{$project->uuid}/agreement")->assertJsonPath('data.wallet_available_minor', 100000);
        $this->postJson(self::CLIENT."/{$project->uuid}/milestones/{$part->uuid}/fund")->assertOk()->assertJsonPath('data.milestones.0.status', 'funded');
        $hold = WalletHold::query()->where('reference_type', 'freelance_milestone')->firstOrFail();
        $this->assertSame($this->money()->countryId(), (int) $hold->wallet->country_id, 'held in the dollar wallet');
        $this->getJson('/api/mobile/v1/wallet')->assertJsonPath('data.total_minor', 5000000);
        $this->getJson('/api/mobile/v1/freelance/home')->assertOk()->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.wallet_minor', 0);
    }

    public function test_the_policy_and_the_plan_prices_are_one_set_for_the_world(): void
    {
        $policies = app(FreelancePolicies::class);
        $policies->save($this->egypt->id, null, ['commission.value' => 1500]);

        $this->assertSame(1500, $policies->for($this->saudi->id)->get('commission.value'));
        $this->assertSame(1500, $policies->for($this->egypt->id, $this->web->id)->get('commission.value'));
        $this->assertSame($this->money()->countryId(), (int) $policies->own($this->saudi->id, null)->country_id);

        FreelancePlan::query()->delete();
        app(FreelancePlanDefaults::class)->seed();
        $plus = FreelancePlan::query()->where('slug', 'plus')->firstOrFail();
        $this->assertSame([$this->money()->countryId()], FreelancePlanPrice::query()->pluck('country_id')->unique()->values()->map(fn ($v) => (int) $v)->all());
        $this->assertSame(900, (int) FreelancePlanPrice::query()->where(['plan_id' => $plus->id, 'period' => 'monthly'])->value('price_minor'), '9 dollars');

        $this->asSara();
        $this->getJson('/api/freelance/v1/plans')->assertOk()->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.plans', fn ($plans) => collect($plans)->firstWhere('slug', 'plus')['prices']['monthly'] === 900);
    }

    public function test_an_owner_whose_local_currency_is_the_dollar_gets_no_international_wallet(): void
    {
        $usd = Currency::query()->where('code', 'USD')->first() ?? Currency::create(['code' => 'USD', 'symbol' => '$']);
        $us = Country::create(['code' => 'US', 'dial_code' => '+1', 'phone_length' => 10, 'status' => true, 'flag_id' => Flag::create(['code' => 'us'])->id, 'currency_id' => $usd->id]);
        $john = User::query()->create(['name' => 'John', 'phone' => '+12025550101', 'country_id' => $us->id, 'status' => 'active', 'phone_verified_at' => now()]);

        // His freelance money is his own US wallet, and no international one is made for him.
        $wallet = $this->money()->wallet($john);
        $this->assertSame($us->id, $wallet->country_id);
        $this->assertNull(\Modules\Wallet\Models\Wallet::query()->where('owner_id', $john->id)->where('country_id', $this->money()->countryId())->first());
        // A Saudi client still gets the international one.
        $this->assertSame($this->money()->countryId(), $this->money()->wallet($this->client)->country_id);
    }
}
