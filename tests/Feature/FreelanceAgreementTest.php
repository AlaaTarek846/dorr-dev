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
 * FL-401: after the client chooses, an agreement is drafted from the offer with the policy frozen in; the client writes what the work
 * covers and sends it; the freelancer approves (the work starts, the chat is no longer kept to Dorr) or declines (the client chooses
 * again). Every step is on record; an active agreement can't change; a cancelled awarded project tells the chosen freelancer.
 */
class FreelanceAgreementTest extends TestCase
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

    public function test_choosing_drafts_the_agreement_with_the_policy_frozen_and_both_approve_it(): void
    {
        $this->chooseSara();
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertJsonPath('data.agreement.status', 'draft');
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.price_minor', 350000)
            ->assertJsonPath('data.duration_days', 21)->assertJsonPath('data.terms.review_days', 7)->assertJsonPath('data.terms.free_revisions', 2)->assertJsonPath('data.can.send', true);

        // The freelancer doesn't see a draft.
        $this->asFreelancer($this->sara);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/agreement")->assertNotFound();

        // The client writes what it covers and sends it.
        $this->asClient();
        $this->putJson(self::CLIENT."/{$this->project->uuid}/agreement", ['scope' => 'A booking site: doctors, times, reminders.', 'includes' => 'Design, build, one month of fixes.', 'excludes' => 'Hosting'])
            ->assertOk()->assertJsonPath('data.includes', 'Design, build, one month of fixes.');
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.agreement.received')->count());

        // A policy change later doesn't touch it.
        FreelancePolicy::query()->create(['country_id' => $this->saudi->id, 'data' => ['review.days' => 3], 'status' => true]);
        FreelancePolicies::forget();

        $this->asFreelancer($this->sara);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/agreement")->assertOk()->assertJsonPath('data.terms.review_days', 7)
            ->assertJsonPath('data.money.price_minor', 350000)->assertJsonPath('data.can.approve', true);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/my-offer")->assertJsonPath('data.offer.agreement.status', 'pending_approval');
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/approve")->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame(FreelanceProjectStatus::InProgress, $this->project->refresh()->status);
        $this->assertSame(['agreement.sent', 'agreement.approved'], $this->approvals());
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.agreement.active')->count());

        // Frozen: nothing changes now.
        $this->asClient();
        $this->putJson(self::CLIENT."/{$this->project->uuid}/agreement", ['scope' => 'Something else'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_agreement_invalid_state');
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/cancel")->assertStatus(409);
    }

    public function test_a_part_of_an_agreement_that_is_not_active_sends_its_can_as_an_object(): void
    {
        $this->chooseSara();
        // The apps read `can` as a map: an empty PHP array would go out as a JSON list and break them.
        $response = $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertOk();
        $this->assertStringContainsString('"can":{}', $response->baseResponse->getContent());
    }

    public function test_after_the_agreement_the_chat_takes_contact_details(): void
    {
        $this->chooseSara();
        $room = $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->offer($this->sara)->uuid}/chat")->json('data.uuid');
        $this->postJson("/api/mobile/v1/freelance/chat/conversations/{$room}/messages", ['body' => 'call me 0501234567'])->assertStatus(422);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/approve")->assertOk();
        $this->asClient();
        $this->postJson("/api/mobile/v1/freelance/chat/conversations/{$room}/messages", ['body' => 'call me 0501234567'])->assertCreated();
    }

    public function test_changing_it_after_sending_takes_the_approval_back(): void
    {
        $this->chooseSara();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();
        $this->putJson(self::CLIENT."/{$this->project->uuid}/agreement", ['excludes' => 'Hosting and the domain'])->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 2);
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/approve")->assertNotFound();
        $this->assertSame(['agreement.sent', 'agreement.withdrawn'], $this->approvals());
    }

    public function test_a_declined_agreement_lets_the_client_choose_again(): void
    {
        $this->chooseSara();
        $this->assertSame('rejected', $this->offer($this->lina)->status->value);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();

        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/decline", ['reason' => 'I am booked this month'])->assertOk()->assertJsonPath('data.status', 'declined');
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/my-offer")->assertJsonPath('data.offer.status', 'withdrawn');

        $project = $this->project->refresh();
        $this->assertSame(FreelanceProjectStatus::Published, $project->status);
        $this->assertNull($project->offers_closed_at);
        $this->assertSame('viewed', $this->offer($this->lina)->status->value, 'turned down only because Sara was chosen: back');
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.agreement.declined')->count());

        // The client chooses Lina; a new agreement is drafted for her.
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->offer($this->lina)->uuid}/choose")->assertOk();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.status', 'draft')->assertJsonPath('data.freelancer.name', 'Lina');
        $this->assertSame(2, FreelanceAgreement::query()->count());
    }

    public function test_the_client_cancels_or_the_time_runs_out(): void
    {
        $this->chooseSara();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.agreement.cancelled')->count());
        $this->assertSame(FreelanceProjectStatus::Published, $this->project->refresh()->status);
        $this->asFreelancer($this->sara);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/my-offer")->assertJsonPath('data.offer.status', 'rejected')
            ->assertJsonPath('data.offer.status_reason', __('freelance.offer_reasons.agreement_cancelled'));

        // Lina is chosen, sent, and doesn't answer in time.
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->offer($this->lina)->uuid}/choose")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();
        $this->travel(8)->days();
        $this->artisan('freelance:expire-agreements')->assertSuccessful();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.status', 'expired');
        $this->assertSame(FreelanceProjectStatus::Published, $this->project->refresh()->status);
        $this->assertSame(1, $this->lina->notifications()->where('data->event', 'freelance.agreement.expired')->count());
    }

    public function test_cancelling_an_awarded_project_tells_the_chosen_freelancer_and_others_see_nothing(): void
    {
        $this->chooseSara();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();

        $this->asFreelancer($this->lina);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/agreement")->assertNotFound();
        $other = User::query()->create(['name' => 'Ali', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, [], 'user_api');
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertNotFound();

        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/cancel", [])->assertOk();
        $this->assertSame('cancelled', FreelanceAgreement::query()->firstOrFail()->status);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.project.cancelled')->count());
    }

    public function test_my_projects_lists_the_agreements_i_was_chosen_for_without_the_draft(): void
    {
        $this->chooseSara();

        // A draft the client has not sent is not mine to see yet.
        $this->asFreelancer($this->sara);
        $this->getJson('/api/freelance/v1/my-projects')->assertOk()->assertJsonCount(0, 'data');

        $this->asClient();
        $this->putJson(self::CLIENT."/{$this->project->uuid}/agreement", ['scope' => 'A booking site: doctors, times, reminders.', 'includes' => 'Design, build.', 'excludes' => 'Hosting'])->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();

        $this->asFreelancer($this->sara);
        $this->getJson('/api/freelance/v1/my-projects?group=active')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending_approval')->assertJsonPath('data.0.project.uuid', $this->project->uuid)->assertJsonPath('data.0.price_minor', 350000);
        $this->getJson('/api/freelance/v1/my-projects?group=completed')->assertOk()->assertJsonCount(0, 'data');

        // Another freelancer sees nothing of it.
        $this->asFreelancer($this->lina);
        $this->getJson('/api/freelance/v1/my-projects')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/freelance/v1/my-projects?group=bad')->assertStatus(422);

        // `all` is the default and `disputes` holds only the ones with an unsettled dispute.
        $this->asFreelancer($this->sara);
        $this->getJson('/api/freelance/v1/my-projects?group=all')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.has_dispute', false);
        $this->getJson('/api/freelance/v1/my-projects?group=disputes')->assertOk()->assertJsonCount(0, 'data');
    }
}
