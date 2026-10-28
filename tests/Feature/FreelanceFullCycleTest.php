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
 * FL-1208: the whole life of a project in one run (offer → agreement → funding → delivery → approval → release → rating), the money
 * adding up to the cent, and repeated calls (a double tap, a retry) never charging or paying twice.
 */
class FreelanceFullCycleTest extends TestCase
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
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'ratings'] as $feature) {
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

    /** Funds the part, starts and delivers it. */
    private function delivered(string $uuid): void
    {
        $this->asClient();
        $this->fundCall($uuid)->assertOk();
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$uuid}/start")->assertOk();
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$uuid}/deliver", ['note' => 'Done, see the demo.'])->assertOk();
    }

    private function wallet($owner): \Modules\Wallet\Models\Wallet
    {
        return app(\Modules\Wallet\Services\WalletService::class)->firstOrCreateWallet($owner, $this->saudi)->fresh();
    }

    private function income(string $slug): int
    {
        return (int) \Modules\Wallet\Models\FinancialEntry::query()->whereHas('category', fn ($q) => $q->where('slug', $slug))->sum('amount_minor');
    }

    private function feesOn(): void
    {
        foreach (['client_fee', 'commission'] as $f) {
            FreelanceFeatureRule::query()->create(['feature' => $f, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
    }

    private const CLIENT_SCORES = ['quality' => 5, 'timeliness' => 4, 'communication' => 5, 'professionalism' => 4];

    private const FREELANCER_SCORES = ['clarity' => 4, 'responsiveness' => 4, 'payment' => 5, 'conduct' => 5];

    public function test_the_whole_cycle_runs_and_every_riyal_is_accounted_for(): void
    {
        $this->feesOn();
        [$one, $two] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(1000000);
        $start = (int) $this->wallet($this->client)->withdrawable_minor;

        // Part one: fund, work, deliver, approve.
        $this->delivered($one);
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/approve")->assertOk();
        // Part two: the same way.
        $this->delivered($two);
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$two}/approve")->assertOk();

        $this->assertSame(FreelanceProjectStatus::Closed, $this->project->refresh()->status);
        $this->assertContains('agreement.completed', $this->approvals());

        // Price 350000: client fee 2% = 7000, commission 10% = 35000, freelancer gets 315000.
        $client = $this->wallet($this->client);
        $this->assertSame(0, (int) $client->held_withdrawable_minor + (int) $client->held_spend_only_minor);
        $this->assertSame($start - 357000, (int) $client->withdrawable_minor);
        $this->assertSame(315000, (int) $this->wallet($this->sara)->withdrawable_minor);
        $this->assertSame(7000, $this->income('freelance_client_fees'));
        $this->assertSame(35000, $this->income('freelance_commissions'));
        // Nothing appears or vanishes: what the client paid = what the freelancer got + what Dorr kept.
        $this->assertSame(357000, 315000 + $this->income('freelance_client_fees') + $this->income('freelance_commissions'));

        // Both rate, and see each other.
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/review", ['scores' => self::CLIENT_SCORES, 'comment' => 'Great work.'])->assertCreated();
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/review", ['scores' => self::FREELANCER_SCORES])->assertCreated()->assertJsonPath('data.theirs.comment', 'Great work.');
    }

    public function test_repeated_calls_never_charge_or_pay_twice(): void
    {
        $this->feesOn();
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);

        // A double tap on "fund": one hold.
        $this->fundCall($one)->assertOk();
        $this->fundCall($one)->assertOk();
        $this->fundCall($one)->assertOk();
        $this->assertSame(1, $this->holds()->count());
        $held = (int) $this->wallet($this->client)->held_withdrawable_minor + (int) $this->wallet($this->client)->held_spend_only_minor;

        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/start")->assertOk();
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/deliver", ['note' => 'Done.'])->assertOk();
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/deliver", ['note' => 'Done.'])->assertStatus(409);

        // A retry on "approve": paid once.
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/approve")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/approve")->assertStatus(409);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/approve")->assertStatus(409);
        $this->assertSame(90000, (int) $this->wallet($this->sara)->withdrawable_minor);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.payment.released')->count());
        $this->assertSame(2000, $this->income('freelance_client_fees'));
        $this->assertSame(10000, $this->income('freelance_commissions'));
        $this->assertSame(102000, $held);
        $this->assertSame(500000 - 102000, (int) $this->wallet($this->client)->withdrawable_minor);
    }

    public function test_choosing_a_second_freelancer_changes_nothing(): void
    {
        $this->chooseSara();
        // The project is awarded: Lina cannot be chosen on top of Sara.
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->offer($this->lina)->uuid}/choose")->assertStatus(409);
        $this->assertSame(1, FreelanceAgreement::query()->where('project_id', $this->project->id)->count());
    }
}
