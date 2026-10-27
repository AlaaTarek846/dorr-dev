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
 * FL-402: an agreement is paid in parts — one for the whole price unless the client splits it; the parts add up to the price,
 * within the policy's count and minimum, each with its own fee, commission and net; frozen once both approve.
 */
class FreelanceMilestonesTest extends TestCase
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
        foreach (['freelance', 'projects', 'bidding', 'chat'] as $feature) {
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

    public function test_one_part_for_the_whole_price_until_the_client_splits_it(): void
    {
        $this->chooseSara();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonCount(1, 'data.milestones')
            ->assertJsonPath('data.milestones.0.amount_minor', 350000)->assertJsonPath('data.milestones.0.title', __('freelance.milestone_default'))
            ->assertJsonPath('data.milestone_rules.max_count', 5)->assertJsonPath('data.milestone_rules.min_amount_minor', 5000)->assertJsonPath('data.can.split', true);

        $this->split([100000, 250000])->assertOk()->assertJsonCount(2, 'data.milestones')->assertJsonPath('data.milestones.1.title', 'Part 2')
            ->assertJsonPath('data.milestones.1.amount_minor', 250000)->assertJsonPath('data.version', 2);
    }

    public function test_the_parts_must_add_up_and_stay_within_the_policy(): void
    {
        $this->chooseSara();
        $this->split([100000, 200000])->assertStatus(422)->assertJsonPath('error_code', 'freelance_milestones_total');
        $this->split([346000, 4000])->assertStatus(422)->assertJsonPath('error_code', 'freelance_milestone_too_small');
        $this->split([70000, 70000, 70000, 70000, 35000, 35000])->assertStatus(422)->assertJsonPath('error_code', 'freelance_milestones_too_many');
        $this->putJson(self::CLIENT."/{$this->project->uuid}/agreement/milestones", ['milestones' => []])->assertStatus(422);
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonCount(1, 'data.milestones');
    }

    public function test_each_part_has_its_money_each_side_sees_its_own_and_it_freezes_with_the_agreement(): void
    {
        FreelanceFeatureRule::query()->create(['feature' => 'client_fee', 'country_id' => $this->saudi->id, 'enabled' => true]);
        FreelanceFeatureRule::query()->create(['feature' => 'commission', 'country_id' => $this->saudi->id, 'enabled' => true]);
        FreelanceFeatures::forget();
        $this->chooseSara();
        $this->split([100000, 250000])->assertOk();
        // 2% client fee, 10% commission (the defaults).
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.milestones.0.client_fee_minor', 2000)->assertJsonPath('data.milestones.0.total_minor', 102000)
            ->assertJsonMissingPath('data.milestones.0.net_minor');
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();

        $this->asFreelancer($this->sara);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/agreement")->assertJsonPath('data.milestones.1.commission_minor', 25000)->assertJsonPath('data.milestones.1.net_minor', 225000)
            ->assertJsonMissingPath('data.milestones.1.client_fee_minor');
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/approve")->assertOk()->assertJsonPath('data.money.net_minor', 315000);

        $this->asClient();
        $this->split([175000, 175000])->assertStatus(409)->assertJsonPath('error_code', 'freelance_agreement_invalid_state');
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.money.client_fee_minor', 7000)->assertJsonPath('data.can.split', false);
    }

    public function test_splitting_after_sending_takes_it_back_and_the_freelancer_cannot_split(): void
    {
        $this->chooseSara();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();
        $this->split([175000, 175000])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertSame(['agreement.sent', 'agreement.withdrawn'], $this->approvals());

        $this->asFreelancer($this->sara);
        $this->putJson("/api/mobile/v1/freelance/projects/{$this->project->uuid}/agreement/milestones", ['milestones' => [['title' => 'x', 'amount_minor' => 350000]]])->assertUnauthorized();
    }
}
