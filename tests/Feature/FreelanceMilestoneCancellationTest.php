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
 * FL-506 / FL-407: a funded part nobody started goes back in full; one with work on it ends only when both sides agree, the freelancer
 * keeping the dashboard's share; a project with work started cannot be cancelled; the free rounds of changes are limited.
 */
class FreelanceMilestoneCancellationTest extends TestCase
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

    private function started(string $uuid): void
    {
        $this->asClient();
        $this->fundCall($uuid)->assertOk();
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$uuid}/start")->assertOk();
    }

    private function c(string $uuid, string $action, array $body = [])
    {
        return $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$uuid}/{$action}", $body);
    }

    private function f(string $uuid, string $action, array $body = [])
    {
        return $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$uuid}/{$action}", $body);
    }

    public function test_a_funded_part_nobody_started_goes_back_in_full_from_either_side(): void
    {
        $this->feesOn();
        [$one, $two] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->fundCall($one)->assertOk();
        $this->assertSame(500000 - 102000, (int) $this->wallet($this->client)->fresh()->availableMinor(\Modules\Wallet\Enums\WalletBucket::Withdrawable));

        $this->c($one, 'cancel', ['reason' => 'Changed my mind'])->assertOk()->assertJsonPath('data.milestones.0.status', 'cancelled')->assertJsonPath('data.milestones.0.refunded_minor', 102000);
        $this->assertSame(500000, (int) $this->wallet($this->client)->fresh()->availableMinor(\Modules\Wallet\Enums\WalletBucket::Withdrawable), 'everything is free again');
        $this->assertSame(0, (int) $this->wallet($this->sara)->withdrawable_minor);
        $this->assertSame(0, $this->income('freelance_client_fees') + $this->income('freelance_commissions'), 'nothing is booked');
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.milestone.called_off')->count());

        // The freelancer can step back from the next one before starting it.
        $this->fundCall($two)->assertOk();
        $this->asFreelancer($this->sara);
        $this->f($two, 'withdraw', ['note' => 'Booked up'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(500000, (int) $this->wallet($this->client)->fresh()->availableMinor(\Modules\Wallet\Enums\WalletBucket::Withdrawable));
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.milestone.called_off')->count());
        $this->assertSame(FreelanceProjectStatus::Closed, $this->project->refresh()->status);
    }

    public function test_a_started_part_ends_only_when_both_agree_and_the_dashboards_share_goes_to_the_freelancer(): void
    {
        $this->feesOn();
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->started($one);

        // The client asks; nothing moves yet, and they can't ask twice.
        $this->asClient();
        $this->c($one, 'cancel', ['reason' => 'Not what I need'])->assertOk()->assertJsonPath('data.milestones.0.status', 'in_progress')->assertJsonPath('data.milestones.0.cancel_requested_by', 'client');
        $this->c($one, 'cancel')->assertStatus(409)->assertJsonPath('error_code', 'freelance_milestone_cancel_pending');
        $this->c($one, 'cancel-accept')->assertStatus(409)->assertJsonPath('error_code', 'freelance_milestone_no_cancel_request');
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.milestone.cancel_requested')->count());

        // The freelancer says no: it goes on.
        $this->asFreelancer($this->sara);
        $this->f($one, 'cancel-reject')->assertOk()->assertJsonPath('data.milestones.0.cancel_requested_by', null);
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.milestone.cancel_rejected')->count());

        // Asked again and agreed: 30% (the default; the dashboard changes it) of 100000 is 30000, commission 10% of it, fee 2% of 30%.
        $this->asClient();
        $this->c($one, 'cancel')->assertOk();
        $this->asFreelancer($this->sara);
        $this->f($one, 'cancel-accept')->assertOk()->assertJsonPath('data.milestones.0.status', 'cancelled')->assertJsonPath('data.milestones.0.paid_minor', 27000)->assertJsonPath('data.milestones.0.refunded_minor', 71400);
        $this->assertSame(27000, (int) $this->wallet($this->sara)->withdrawable_minor);
        // Held 102000: used 30000 + 600; the 71400 left is free again.
        $client = $this->wallet($this->client)->fresh();
        $this->assertSame(0, (int) $client->held_withdrawable_minor + (int) $client->held_spend_only_minor);
        $this->assertSame(500000 - 30600, (int) $client->availableMinor(\Modules\Wallet\Enums\WalletBucket::Withdrawable));
        $this->assertSame(600, $this->income('freelance_client_fees'));
        $this->assertSame(3000, $this->income('freelance_commissions'));
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.milestone.cancelled')->count());
    }

    public function test_the_share_comes_from_the_policy_set_in_the_dashboard(): void
    {
        [$one] = $this->activeInTwo();
        // The agreement was made under the default 30%; a policy change later does not touch it.
        \Modules\Freelance\Models\FreelancePolicy::query()->create(['country_id' => $this->saudi->id, 'data' => ['cancellation.started_freelancer_bp' => 8000], 'status' => true]);
        \Modules\Freelance\Services\Policy\FreelancePolicies::forget();
        $this->asClient();
        $this->topUp(500000);
        $this->started($one);
        $this->asClient();
        $this->c($one, 'cancel')->assertOk();
        $this->asFreelancer($this->sara);
        $this->f($one, 'cancel-accept')->assertOk()->assertJsonPath('data.milestones.0.paid_minor', 30000)->assertJsonPath('data.milestones.0.refunded_minor', 70000);
        $this->assertSame(30000, (int) $this->wallet($this->sara)->withdrawable_minor);

        // An agreement frozen with 0% gives everything back to the client.
        \Modules\Freelance\Models\FreelanceAgreement::query()->each(fn ($a) => $a->forceFill(['policy' => ['cancellation.started_freelancer_bp' => 0] + (array) $a->policy])->save());
        $two = \Modules\Freelance\Models\FreelanceMilestone::query()->where('position', 2)->value('uuid');
        $this->started($two);
        $this->asFreelancer($this->sara);
        $this->f($two, 'withdraw')->assertOk();
        $this->asClient();
        $this->c($two, 'cancel-accept')->assertOk()->assertJsonPath('data.milestones.1.refunded_minor', 250000);
        $this->assertSame(30000, (int) $this->wallet($this->sara)->withdrawable_minor, 'nothing more for the freelancer');
    }

    public function test_a_request_nobody_answers_in_time_lapses_and_a_delivered_part_cannot_be_called_off(): void
    {
        [$one, $two] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->started($one);
        $this->asFreelancer($this->sara);
        $this->f($one, 'deliver', ['note' => 'Here it is'])->assertOk();
        $this->asClient();
        $this->c($one, 'cancel')->assertStatus(409)->assertJsonPath('error_code', 'freelance_milestone_invalid_state');
        $this->asFreelancer($this->sara);
        $this->f($one, 'withdraw')->assertStatus(409);

        $this->asClient();
        $this->c($one, 'approve')->assertOk();
        $this->started($two);
        $this->asClient();
        $this->c($two, 'cancel')->assertOk();
        $this->travel(4)->days();
        $this->asFreelancer($this->sara);
        $this->f($two, 'cancel-accept')->assertStatus(409)->assertJsonPath('error_code', 'freelance_milestone_no_cancel_request');
    }

    public function test_cancelling_a_project_frees_funded_parts_and_the_active_agreement_ends(): void
    {
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->fundCall($one)->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/cancel", [])->assertOk();
        $this->assertSame(500000, (int) $this->wallet($this->client)->fresh()->availableMinor(\Modules\Wallet\Enums\WalletBucket::Withdrawable));
        $this->assertSame('cancelled', \Modules\Freelance\Models\FreelanceAgreement::query()->firstOrFail()->status);
        $this->assertSame(0, \Modules\Freelance\Models\FreelanceMilestone::query()->whereNotIn('status', ['cancelled'])->count());
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.project.cancelled')->count());
    }

    public function test_free_rounds_of_changes_are_limited(): void
    {
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->started($one);
        $deliver = function () use ($one) {
            $this->asFreelancer($this->sara);
            $this->f($one, 'deliver', ['note' => 'Delivered again'])->assertOk();
            $this->asClient();
        };
        $deliver();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.milestones.0.revisions_max', 2)->assertJsonPath('data.milestones.0.revisions_left', 2)->assertJsonPath('data.milestones.0.can.revision', true);
        $this->c($one, 'revision', ['reason' => 'First round of changes'])->assertOk()->assertJsonPath('data.milestones.0.revisions_left', 1);
        $deliver();
        $this->c($one, 'revision', ['reason' => 'Second round of changes'])->assertOk()->assertJsonPath('data.milestones.0.revisions_left', 0);
        $deliver();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.milestones.0.can.revision', false);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.milestone.revisions_exhausted')->count(), 'told when the last free round was used');
        $this->c($one, 'revision', ['reason' => 'A third round please'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_revisions_exhausted');
        $this->c($one, 'revision', ['reason' => 'A fourth round please'])->assertStatus(409);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.milestone.revisions_exhausted')->count(), 'not again on every refused try');
        $this->c($one, 'approve')->assertOk();
    }

    public function test_ending_a_started_part_never_pays_out_money_that_is_not_held(): void
    {
        $this->feesOn();
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->started($one);
        $this->asClient();
        $this->c($one, 'cancel')->assertOk();
        // The hold is gone (a fault): agreeing to end it moves nothing.
        \Modules\Wallet\Models\WalletHold::query()->where('reference_type', 'freelance_milestone')->get()->each(fn ($h) => app(\Modules\Wallet\Services\WalletService::class)->release($h, 'test'));
        $this->asFreelancer($this->sara);
        $this->f($one, 'cancel-accept')->assertStatus(409)->assertJsonPath('error_code', 'freelance_milestone_not_funded');
        $this->assertSame(0, (int) $this->wallet($this->sara)->withdrawable_minor);
        $this->assertSame(0, $this->income('freelance_client_fees') + $this->income('freelance_commissions'));
        $this->assertSame('in_progress', \Modules\Freelance\Models\FreelanceMilestone::query()->where('uuid', $one)->first()->status->value);
    }

    public function test_a_request_still_waiting_ends_when_the_part_is_delivered(): void
    {
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->started($one);
        $this->asClient();
        $this->c($one, 'cancel')->assertOk()->assertJsonPath('data.milestones.0.cancel_requested_by', 'client');

        // The freelancer delivers instead of answering; then the client asks for changes — the old request must not come back.
        $this->asFreelancer($this->sara);
        $this->f($one, 'deliver', ['note' => 'Here it is'])->assertOk()->assertJsonPath('data.milestones.0.cancel_requested_by', null);
        $this->asClient();
        $this->c($one, 'revision', ['reason' => 'Small changes please'])->assertOk()->assertJsonPath('data.milestones.0.cancel_requested_by', null);
        $this->asFreelancer($this->sara);
        $this->f($one, 'cancel-accept')->assertStatus(409)->assertJsonPath('error_code', 'freelance_milestone_no_cancel_request');
        $this->assertNull(\Modules\Freelance\Models\FreelanceMilestone::query()->where('uuid', $one)->value('cancel_requested_by'));
    }

    public function test_an_answer_counts_once(): void
    {
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->started($one);
        $this->asClient();
        $this->c($one, 'cancel')->assertOk();
        $this->asFreelancer($this->sara);
        $this->f($one, 'cancel-reject')->assertOk();
        // Already answered: neither a second «no» nor a late «yes» goes through.
        $this->f($one, 'cancel-reject')->assertStatus(409);
        $this->f($one, 'cancel-accept')->assertStatus(409);
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.milestone.cancel_rejected')->count());
    }

    public function test_a_project_in_progress_offers_cancelling_only_while_no_part_has_work_on_it(): void
    {
        [$one] = $this->activeInTwo();
        $this->asClient();
        $this->topUp(500000);
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.can.cancel', true);
        $this->fundCall($one)->assertOk();
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertJsonPath('data.can.cancel', true);
        $this->started($one);
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertJsonPath('data.can.cancel', false);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/cancel", [])->assertStatus(409);
        // Refused as a whole: the funded money stayed held and the agreement is still active.
        $this->assertSame(100000, (int) \Modules\Wallet\Models\WalletHold::query()->where('reference_type', 'freelance_milestone')->where('status', 'active')->sum('amount_minor'));
        $this->assertSame('active', \Modules\Freelance\Models\FreelanceAgreement::query()->value('status'));
    }
}
