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
 * FL-503 / FL-504 / FL-408: approving a part pays it out in one go - the client's hold captured, the freelancer credited the net,
 * the client fee and the commission booked as income; an unanswered delivery approves itself after the review days (with a reminder
 * a day before); once every part is settled the agreement is completed and the project closed.
 */
class FreelanceMilestoneReleaseTest extends TestCase
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

    public function test_approving_pays_the_part_out_and_books_what_dorr_keeps(): void
    {
        $this->feesOn();
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->delivered($one);

        $this->asClient();
        // Part one: 100000; client fee 2% = 2000; commission 10% = 10000; net 90000.
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/approve")->assertOk()->assertJsonPath('data.milestones.0.status', 'approved')
            ->assertJsonPath('data.milestones.0.held_minor', 0);
        $this->assertSame(500000 - 102000, (int) $this->wallet($this->client)->withdrawable_minor);
        $this->assertSame(0, (int) $this->wallet($this->client)->held_withdrawable_minor);
        $this->assertSame(90000, (int) $this->wallet($this->sara)->withdrawable_minor);
        $this->assertSame(2000, $this->income('freelance_client_fees'));
        $this->assertSame(10000, $this->income('freelance_commissions'));
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.payment.released')->count());

        $this->asFreelancer($this->sara);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/agreement")->assertJsonPath('data.milestones.0.paid_minor', 90000);

        // Approving again pays nothing more.
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/approve")->assertStatus(409);
        $this->assertSame(90000, (int) $this->wallet($this->sara)->withdrawable_minor);
    }

    public function test_a_part_without_its_money_is_not_approved_and_nothing_moves(): void
    {
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->delivered($one);
        // Its hold is gone (a fault): approving refuses and the part stays delivered.
        \Modules\Wallet\Models\WalletHold::query()->where('reference_type', 'freelance_milestone')->get()->each(fn ($h) => app(\Modules\Wallet\Services\WalletService::class)->release($h, 'test'));
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/approve")->assertStatus(409)->assertJsonPath('error_code', 'freelance_milestone_not_funded');
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.milestones.0.status', 'delivered');
        $this->assertSame(0, (int) $this->wallet($this->sara)->withdrawable_minor);
    }

    public function test_an_unanswered_delivery_is_approved_by_itself_after_a_reminder(): void
    {
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->delivered($one);
        $this->asClient();
        $this->assertNotNull($this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->json('data.milestones.0.auto_approve_at'));

        // Review days: 7. Day 5: nothing yet.
        $this->travel(5)->days();
        $this->artisan('freelance:release-due')->assertSuccessful();
        $this->assertSame(0, $this->client->notifications()->where('data->event', 'freelance.milestone.review_due')->count());

        // Day 6 and a bit: reminded, once.
        $this->travel(26)->hours();
        $this->artisan('freelance:release-due')->assertSuccessful();
        $this->artisan('freelance:release-due')->assertSuccessful();
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.milestone.review_due')->count());
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.milestones.0.status', 'delivered');

        // Past day 7: approved and paid.
        $this->travel(1)->days();
        $this->artisan('freelance:release-due')->assertSuccessful();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.milestones.0.status', 'approved');
        $this->assertSame(100000, (int) $this->wallet($this->sara)->withdrawable_minor);
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.milestone.auto_approved')->count());
    }

    public function test_no_auto_approval_when_the_policy_says_so(): void
    {
        [$one] = $this->activeInTwo();
        \Modules\Freelance\Models\FreelanceAgreement::query()->each(fn ($a) => $a->forceFill(['policy' => ['review.auto_approve' => false] + (array) $a->policy])->save());
        $this->asClient();
        $this->topUp(500000);
        $this->delivered($one);
        $this->travel(30)->days();
        $this->artisan('freelance:release-due')->assertSuccessful();
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.milestones.0.status', 'delivered')->assertJsonPath('data.milestones.0.auto_approve_at', null);
    }

    public function test_the_project_closes_when_every_part_is_settled(): void
    {
        [$one, $two] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->delivered($one);
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/approve")->assertOk();
        $this->assertSame(FreelanceProjectStatus::InProgress, $this->project->refresh()->status);

        // The second part is dropped before funding: everything is settled.
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$two}/cancel")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(FreelanceProjectStatus::Closed, $this->project->refresh()->status);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.project.completed')->count());
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.project.completed')->count());
        $this->assertContains('agreement.completed', $this->approvals());
    }
}
