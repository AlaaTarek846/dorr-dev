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
 * FL-403: the parts of an active agreement move only as allowed — funded (FL-501 calls it), started and delivered by the
 * freelancer, approved or sent back by the client — in order, each move on record, and only by the right side.
 */
class FreelanceMilestoneWorkTest extends TestCase
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

    /** Funds a part for real (FL-501): the client tops up and the part is held from the wallet. */
    private function fund(string $uuid): void
    {
        $m = \Modules\Freelance\Models\FreelanceMilestone::query()->where('uuid', $uuid)->firstOrFail();
        $w = app(\Modules\Wallet\Services\WalletService::class);
        $w->credit($w->firstOrCreateWallet($this->client, $this->saudi), $m->amount_minor + $m->client_fee_minor, \Modules\Wallet\Enums\WalletBucket::Withdrawable, \Modules\Wallet\Enums\WalletTransactionType::Topup);
        app(\Modules\Freelance\Services\Projects\MilestonePayments::class)->fund($m->load('agreement'), $this->client);
    }

    public function test_a_part_goes_funded_started_delivered_sent_back_and_approved(): void
    {
        [$one] = $this->activeInTwo();
        $f = fn (string $a, array $b = []) => $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/{$a}", $b);
        $c = fn (string $a, array $b = []) => $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/{$a}", $b);

        $f('start')->assertStatus(409)->assertJsonPath('error_code', 'freelance_milestone_invalid_state');
        $this->fund($one);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/agreement")->assertJsonPath('data.milestones.0.status', 'funded')->assertJsonPath('data.milestones.0.can.start', true);
        $f('start')->assertOk()->assertJsonPath('data.milestones.0.status', 'in_progress');
        $f('deliver', ['note' => 'The design and the booking flow.'])->assertOk()->assertJsonPath('data.milestones.0.status', 'delivered');

        $this->asClient();
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.milestone.delivered')->count());
        $c('revision')->assertStatus(422);
        $c('revision', ['reason' => 'Add the reminders page.'])->assertOk()->assertJsonPath('data.milestones.0.status', 'revision')->assertJsonPath('data.milestones.0.revisions_used', 1);
        $c('approve')->assertStatus(409);

        $this->asFreelancer($this->sara);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.milestone.revision')->count());
        $f('deliver', ['note' => 'Reminders added.'])->assertOk();
        $this->asClient();
        $c('approve')->assertOk()->assertJsonPath('data.milestones.0.status', 'approved')->assertJsonPath('data.milestones.0.can', ['fund' => false, 'approve' => false, 'revision' => false, 'checklist_edit' => false, 'checklist_tick' => false, 'cancel' => false, 'cancel_accept' => false, 'cancel_reject' => false, 'dispute' => false]);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.milestone.approved')->count());

        $types = \Illuminate\Support\Facades\DB::table('freelance_milestone_events')->orderBy('id')->pluck('type')->all();
        $this->assertSame(['milestone.funded', 'milestone.started', 'milestone.delivered', 'milestone.revision', 'milestone.delivered', 'milestone.approved'], $types);
    }

    public function test_parts_go_in_order_and_each_side_does_only_its_own_moves(): void
    {
        [$one, $two] = $this->activeInTwo();
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$two}/start")->assertStatus(409);
        try {
            $this->fund($two);
            $this->fail('the second part was funded before the first was done');
        } catch (\Modules\Freelance\Exceptions\FreelanceException $e) {
            $this->assertSame('freelance_milestone_out_of_turn', $e->apiErrorCode());
        }

        // The client can't start or deliver; the freelancer can't approve; another client sees nothing.
        $this->asClient();
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/start")->assertUnauthorized();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/start")->assertNotFound();
        $this->asFreelancer($this->lina);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$one}/start")->assertNotFound();

        // A part not funded yet can be cancelled by the client; then the next one is in turn.
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$one}/cancel")->assertOk()->assertJsonPath('data.milestones.0.status', 'cancelled');
        $this->fund($two);
        // Funded and not started: cancelling now gives the money back (FL-506).
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$two}/cancel")->assertOk()->assertJsonPath('data.milestones.1.status', 'cancelled');
    }

    public function test_no_moves_before_the_agreement_is_active(): void
    {
        $this->chooseSara();
        $uuid = \Modules\Freelance\Models\FreelanceMilestone::query()->value('uuid');
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$uuid}/cancel")->assertNotFound();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.milestones.0.can', []);
    }
}
