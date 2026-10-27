<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelancePolicy;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-501 / FL-502: the client funds a part from the wallet — its amount and client fee held, spend-only first, with no end date
 * so the wallet's safety valve never frees it; not enough money says what is missing; funding twice holds once; parts in order.
 */
class FreelanceMilestoneFundingTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private const FREELANCER = '/api/freelance/v1/projects';

    private Country $saudi;

    private User $client;

    private FreelanceProject $project;

    private Freelancer $sara;

    private Freelancer $lina;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment'] as $feature) {
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
        $this->sara = $this->freelancer('+966500000091', 'Sara');
        $this->lina = $this->freelancer('+966500000092', 'Lina');
    }

    private function freelancer(string $phone, string $name): Freelancer
    {
        $f = Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->asFreelancer($f);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.',
        ])->assertCreated();

        return $f;
    }

    private function asFreelancer(Freelancer $f): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f->refresh(), [], 'freelance_api');
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client->refresh(), [], 'user_api');
    }

    private function offer(Freelancer $f): FreelanceOffer
    {
        return FreelanceOffer::query()->where('freelancer_id', $f->id)->firstOrFail();
    }

    /** The client looks at the offers and chooses Sara. */
    private function chooseSara(): void
    {
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->offer($this->sara)->uuid}/choose")->assertOk();
    }

    /** @return list<string> */
    private function approvals(): array
    {
        return DB::table('freelance_project_approvals')->where('project_id', $this->project->id)->orderBy('id')->pluck('action')->all();
    }

    private function split(array $amounts)
    {
        return $this->putJson(self::CLIENT."/{$this->project->uuid}/agreement/milestones", ['milestones' => array_map(fn ($a, $i) => ['title' => 'Part '.($i + 1), 'amount_minor' => $a], $amounts, array_keys($amounts))]);
    }

    /** Sara's agreement split in two and active; returns the parts' uuids. */
    private function activeInTwo(): array
    {
        $this->chooseSara();
        $this->split([100000, 250000])->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/approve")->assertOk();

        return \Modules\Freelance\Models\FreelanceMilestone::query()->orderBy('position')->pluck('uuid')->all();
    }

    private function topUp(int $withdrawable, int $spendOnly = 0): void
    {
        $w = app(\Modules\Wallet\Services\WalletService::class);
        $wallet = $w->firstOrCreateWallet($this->client, $this->saudi);
        if ($withdrawable > 0) {
            $w->credit($wallet, $withdrawable, \Modules\Wallet\Enums\WalletBucket::Withdrawable, \Modules\Wallet\Enums\WalletTransactionType::Topup);
        }
        if ($spendOnly > 0) {
            $w->credit($wallet, $spendOnly, \Modules\Wallet\Enums\WalletBucket::SpendOnly, \Modules\Wallet\Enums\WalletTransactionType::Topup);
        }
    }

    private function fundCall(string $uuid)
    {
        return $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$uuid}/fund");
    }

    private function holds(): \Illuminate\Support\Collection
    {
        return \Modules\Wallet\Models\WalletHold::query()->where('reference_type', 'freelance_milestone')->get();
    }

    public function test_funding_holds_the_amount_and_fee_spend_only_first_with_no_end_date(): void
    {
        FreelanceFeatureRule::query()->create(['feature' => 'client_fee', 'country_id' => $this->saudi->id, 'enabled' => true]);
        FreelanceFeatures::forget();
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(200000, 30000);
        // Part one: 100000 + 2% fee = 102000.
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.wallet_available_minor', 230000)->assertJsonPath('data.milestones.0.can.fund', true)->assertJsonPath('data.milestones.1.can.fund', false);
        $this->fundCall($one)->assertOk()->assertJsonPath('data.milestones.0.status', 'funded')->assertJsonPath('data.milestones.0.held_minor', 102000)->assertJsonPath('data.wallet_available_minor', 128000);

        $holds = $this->holds();
        $this->assertSame([30000, 72000], $holds->sortBy('id')->pluck('amount_minor')->map(fn ($v) => (int) $v)->values()->all(), 'spend-only first');
        $this->assertTrue($holds->every(fn ($h) => $h->expires_at === null));
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.payment.held')->count());

        // The wallet's safety valve doesn't touch it, even much later.
        $this->travel(30)->days();
        app(\Modules\Wallet\Services\WalletService::class)->releaseExpiredHolds();
        $this->assertSame(2, $this->holds()->where('status', \Modules\Wallet\Enums\WalletHoldStatus::Active)->count());

        // The freelancer can start now.
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/start")->assertOk();
    }

    public function test_not_enough_money_says_what_is_missing_and_holds_nothing(): void
    {
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(40000);
        $this->fundCall($one)->assertStatus(422)->assertJsonPath('error_code', 'freelance_wallet_insufficient')
            ->assertJsonPath('data.shortfall_minor', 60000)->assertJsonPath('data.needed_minor', 100000)->assertJsonPath('data.available_minor', 40000);
        $this->assertSame(0, $this->holds()->count());
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.milestones.0.status', 'unfunded');
    }

    public function test_funding_twice_holds_once_parts_go_in_order_and_only_the_client_funds(): void
    {
        [$one, $two] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->fundCall($two)->assertStatus(409)->assertJsonPath('error_code', 'freelance_milestone_out_of_turn');
        $this->fundCall($one)->assertOk();
        $this->fundCall($one)->assertOk()->assertJsonPath('data.milestones.0.status', 'funded');
        $this->assertSame(1, $this->holds()->count());
        $this->assertSame(100000, (int) $this->holds()->sum('amount_minor'));

        $this->asFreelancer($this->sara);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/fund")->assertUnauthorized();
        $other = User::query()->create(['name' => 'Ali', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, [], 'user_api');
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$two}/fund")->assertNotFound();
    }

    public function test_a_project_with_work_started_cannot_be_cancelled_and_the_switch_closes_funding(): void
    {
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->fundCall($one)->assertOk();
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/start")->assertOk();
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/cancel", [])->assertStatus(409)->assertJsonPath('error_code', 'freelance_project_has_funded_work');

        FreelanceFeatureRule::query()->where('feature', 'wallet_payment')->delete();
        FreelanceFeatureRule::query()->create(['feature' => 'wallet_payment', 'country_id' => $this->saudi->id, 'enabled' => false]);
        FreelanceFeatures::forget();
        $two = \Modules\Freelance\Models\FreelanceMilestone::query()->where('position', 2)->value('uuid');
        $this->fundCall($two)->assertStatus(403);
    }
}
