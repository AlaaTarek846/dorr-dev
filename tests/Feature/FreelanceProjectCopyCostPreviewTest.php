<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelancePolicy;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Catalog\FreelanceSkills;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-209 (ask for a project again as a copy), FL-210 (the most the client will pay, and the last look before sending that catches
 * what doesn't add up) and FL-211 (my projects: where each stands, what is due from me, and all the parts of the work).
 */
class FreelanceProjectCopyCostPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private const FREELANCER = '/api/freelance/v1/projects';

    private Country $saudi;

    private User $client;

    private FreelanceProject $project;

    private FreelanceSkill $laravel;

    private Freelancer $sara;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->laravel = FreelanceSkill::query()->create(['slug' => 'laravel', 'status' => FreelanceSkill::ACTIVE]);
        $this->laravel->translations()->create(['locale' => 'en', 'name' => 'Laravel', 'normalized' => FreelanceSkills::normalize('Laravel')]);

        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->project = FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'range', 'budget_min_minor' => 300000, 'budget_max_minor' => 500000,
            'currency_code' => 'SAR', 'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(),
            'offers_deadline_at' => now()->addDays(14), 'questions' => ['Have you built a booking site before?'],
        ]);
        $this->project->skills()->sync([$this->laravel->id]);

        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->asFreelancer();
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.',
            'answers' => ['Yes, three of them.'],
        ])->assertCreated();
        $this->asClient();
    }

    private function asClient(?User $user = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(($user ?? $this->client)->refresh(), [], 'user_api');
    }

    private function asFreelancer(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->sara->refresh(), [], 'freelance_api');
    }

    private function sarasOffer(): FreelanceOffer
    {
        return FreelanceOffer::query()->where('freelancer_id', $this->sara->id)->firstOrFail();
    }

    /** What the client would pay in all if they chose Sara's offer (the fee on top, as the offers list says). */
    private function sarasTotal(): int
    {
        return $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk()->json('data.0.client_cost.total_minor');
    }

    /** The client looks at the offers (that is what makes them «seen») and chooses Sara's. */
    private function choose(): void
    {
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->sarasOffer()->uuid}/choose")->assertOk();
    }

    /** The agreement sent, approved by Sara: the work is in progress with one part, not yet funded. */
    private function inProgress(): void
    {
        $this->choose();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();
        $this->asFreelancer();
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/approve")->assertOk();
        $this->asClient();
    }

    // ------------------------------------------------------------------ FL-209

    public function test_a_project_is_asked_for_again_as_a_draft_copy_with_nothing_else_coming_along(): void
    {
        $this->project->update(['max_cost_minor' => 900000]);
        $this->choose();

        $copy = $this->postJson(self::CLIENT."/{$this->project->uuid}/duplicate")->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.title', 'A booking website')->assertJsonPath('data.visibility', 'public')
            ->assertJsonPath('data.specialty.name', 'Web')->assertJsonPath('data.skills.0.name', 'Laravel')
            ->assertJsonPath('data.budget.min_minor', 300000)->assertJsonPath('data.budget.max_minor', 500000)->assertJsonPath('data.max_cost_minor', 900000)
            ->assertJsonPath('data.duration_days', 30)->assertJsonPath('data.questions', ['Have you built a booking site before?'])
            ->assertJsonPath('data.missing', [])->assertJsonPath('data.can.submit', true)
            ->assertJsonPath('data.timeline.0.type', 'project.created')
            ->assertJsonPath('data.agreement', null)->assertJsonPath('data.offers.count', 0)->assertJsonCount(0, 'data.attachments');
        $uuid = $copy->json('data.uuid');
        $this->assertNotSame($this->project->uuid, $uuid);
        $this->assertSame(FreelanceProjectStatus::Awarded, $this->project->refresh()->status);

        // The files come along (a draft can hold them), as the copy's own.
        $this->post(self::CLIENT."/{$uuid}/attachments", ['file' => UploadedFile::fake()->createWithContent('brief.pdf', "%PDF-1.4
%%EOF")], ['Accept' => 'application/json'])->assertCreated();
        $second = $this->postJson(self::CLIENT."/{$uuid}/duplicate")->assertCreated()->assertJsonCount(1, 'data.attachments');
        $this->deleteJson(self::CLIENT."/{$second->json('data.uuid')}/attachments/{$second->json('data.attachments.0.id')}")->assertOk()->assertJsonCount(0, 'data.attachments');
        $this->getJson(self::CLIENT."/{$uuid}")->assertJsonCount(1, 'data.attachments');

        // The copy goes through the same road as any draft, and says which project it came from.
        $this->postJson(self::CLIENT."/{$second->json('data.uuid')}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->assertSame($uuid, \Modules\Freelance\Models\FreelanceProjectEvent::query()->where('type', 'project.created')->latest('id')->first()->payload['copied_from'] ?? null);
    }

    public function test_a_copy_leaves_out_a_skill_that_was_switched_off_and_respects_the_open_projects_limit(): void
    {
        $this->laravel->update(['status' => FreelanceSkill::INACTIVE]);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/duplicate")->assertCreated()->assertJsonPath('data.skills', [])->assertJsonPath('data.missing', ['skills']);

        config(['freelance.projects.max_open' => 2]);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/duplicate")->assertUnprocessable()->assertJsonPath('error_code', 'freelance_project_limit');
    }

    public function test_only_the_owner_can_copy_a_project(): void
    {
        $other = User::query()->create(['name' => 'Omar', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->asClient($other);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/duplicate")->assertNotFound();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/preview")->assertNotFound();
        $this->putJson(self::CLIENT."/{$this->project->uuid}/cost-cap", ['max_cost_minor' => 1000])->assertNotFound();
    }

    // ------------------------------------------------------------------ FL-210: the cost limit

    public function test_an_offer_dearer_than_the_limit_is_marked_and_cannot_be_chosen_until_the_limit_moves(): void
    {
        $total = $this->sarasTotal();
        $this->putJson(self::CLIENT."/{$this->project->uuid}/cost-cap", ['max_cost_minor' => $total - 1])->assertOk()
            ->assertJsonPath('data.max_cost_minor', $total - 1)->assertJsonPath('data.can.set_cost_cap', true);

        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertJsonPath('data.0.over_cap', true);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->sarasOffer()->uuid}/choose")->assertUnprocessable()
            ->assertJsonPath('error_code', 'freelance_project_cost_cap_exceeded')->assertJsonPath('data.max_cost_minor', $total - 1)->assertJsonPath('data.total_minor', $total);
        $this->assertSame(FreelanceProjectStatus::Published, $this->project->refresh()->status);
        $this->assertNotSame('accepted', $this->sarasOffer()->status->value);

        // Exactly the cost is within the limit.
        $this->putJson(self::CLIENT."/{$this->project->uuid}/cost-cap", ['max_cost_minor' => $total])->assertOk();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertJsonPath('data.0.over_cap', false);
        $this->choose();
        $this->assertSame(FreelanceProjectStatus::Awarded, $this->project->refresh()->status);
    }

    public function test_the_agreement_cannot_be_sent_when_it_costs_more_than_the_limit_set_after_choosing(): void
    {
        $this->choose();
        $this->putJson(self::CLIENT."/{$this->project->uuid}/cost-cap", ['max_cost_minor' => 100000])->assertOk()->assertJsonPath('data.can.set_cost_cap', true);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertUnprocessable()->assertJsonPath('error_code', 'freelance_project_cost_cap_exceeded');

        // Lifting the limit lets it go.
        $this->deleteJson(self::CLIENT."/{$this->project->uuid}/cost-cap")->assertOk()->assertJsonPath('data.max_cost_minor', null);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk()->assertJsonPath('data.status', 'pending_approval');
    }

    public function test_the_limit_is_set_with_the_draft_and_is_shaped_right_and_locked_once_the_work_starts(): void
    {
        $uuid = $this->postJson(self::CLIENT, ['title' => 'Logo', 'max_cost_minor' => 250000])->assertCreated()->assertJsonPath('data.max_cost_minor', 250000)->json('data.uuid');
        $this->putJson(self::CLIENT."/{$uuid}", ['max_cost_minor' => 300000])->assertOk()->assertJsonPath('data.max_cost_minor', 300000);

        $this->putJson(self::CLIENT."/{$uuid}/cost-cap", [])->assertUnprocessable();
        $this->putJson(self::CLIENT."/{$uuid}/cost-cap", ['max_cost_minor' => 0])->assertUnprocessable();
        $this->putJson(self::CLIENT."/{$uuid}/cost-cap", ['max_cost_minor' => 'lots'])->assertUnprocessable();
        $this->putJson(self::CLIENT."/{$uuid}/cost-cap", ['max_cost_minor' => null])->assertUnprocessable();

        $this->inProgress();
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertJsonPath('data.can.set_cost_cap', false);
        $this->putJson(self::CLIENT."/{$this->project->uuid}/cost-cap", ['max_cost_minor' => 5000000])->assertConflict()->assertJsonPath('error_code', 'freelance_project_invalid_state');
        $this->deleteJson(self::CLIENT."/{$this->project->uuid}/cost-cap")->assertConflict();
    }

    // ------------------------------------------------------------------ FL-210: the last look

    public function test_the_preview_of_an_incomplete_draft_says_what_is_missing_and_blocks_sending(): void
    {
        $uuid = $this->postJson(self::CLIENT, ['title' => 'Logo'])->assertCreated()->json('data.uuid');

        $r = $this->getJson(self::CLIENT."/{$uuid}/preview")->assertOk()->assertJsonPath('data.can_submit', false)->assertJsonPath('data.project.uuid', $uuid);
        $this->assertSame(['missing_title', 'missing_description', 'missing_specialty', 'missing_skills', 'missing_budget', 'missing_duration'], array_column($r->json('data.issues'), 'code'));
        $this->assertSame(['error'], array_values(array_unique(array_column($r->json('data.issues'), 'severity'))));
        $this->assertNotEmpty($r->json('data.issues.0.message'));
    }

    public function test_a_clean_project_can_be_sent_from_the_preview(): void
    {
        $uuid = $this->postJson(self::CLIENT."/{$this->project->uuid}/duplicate")->assertCreated()->json('data.uuid');
        $this->putJson(self::CLIENT."/{$uuid}", ['budget_type' => 'range', 'budget_min_minor' => 300000, 'budget_max_minor' => 500000])->assertOk();

        $this->getJson(self::CLIENT."/{$uuid}/preview")->assertOk()->assertJsonPath('data.issues', [])->assertJsonPath('data.can_submit', true);
    }

    public function test_the_preview_warns_about_a_budget_that_is_all_over_the_place_and_a_limit_that_does_not_fit_it(): void
    {
        $uuid = $this->postJson(self::CLIENT."/{$this->project->uuid}/duplicate")->assertCreated()->json('data.uuid');

        // Top more than five times the bottom: only a warning, sending stays possible.
        $this->putJson(self::CLIENT."/{$uuid}", ['budget_min_minor' => 100000, 'budget_max_minor' => 900000])->assertOk();
        $r = $this->getJson(self::CLIENT."/{$uuid}/preview")->assertOk()->assertJsonPath('data.can_submit', true);
        $this->assertSame([['warning', 'budget_range_wide', 'budget']], array_map(fn ($i) => [$i['severity'], $i['code'], $i['field']], $r->json('data.issues')));

        // A limit under even the lowest budget (the client fee comes on top of it) could never be met: that is an error.
        $this->putJson(self::CLIENT."/{$uuid}/cost-cap", ['max_cost_minor' => 50000])->assertOk();
        $r = $this->getJson(self::CLIENT."/{$uuid}/preview")->assertOk()->assertJsonPath('data.can_submit', false);
        $this->assertContains(['error', 'cost_cap_below_budget', 'max_cost'], array_map(fn ($i) => [$i['severity'], $i['code'], $i['field']], $r->json('data.issues')));

        // A limit between the bottom and the top of the budget: a warning, since offers near the top will be refused.
        $this->putJson(self::CLIENT."/{$uuid}", ['budget_min_minor' => 300000, 'budget_max_minor' => 500000])->assertOk();
        $this->putJson(self::CLIENT."/{$uuid}/cost-cap", ['max_cost_minor' => 400000])->assertOk();
        $r = $this->getJson(self::CLIENT."/{$uuid}/preview")->assertOk()->assertJsonPath('data.can_submit', true);
        $this->assertSame([['warning', 'cost_cap_below_budget_top', 'max_cost']], array_map(fn ($i) => [$i['severity'], $i['code'], $i['field']], $r->json('data.issues')));
    }

    public function test_the_preview_warns_when_the_time_and_the_money_do_not_agree(): void
    {
        FreelancePolicy::query()->create(['country_id' => $this->saudi->id, 'data' => ['project.min_budget_minor' => 10000], 'status' => true]);
        FreelancePolicies::forget();
        $uuid = $this->postJson(self::CLIENT."/{$this->project->uuid}/duplicate")->assertCreated()->json('data.uuid');
        $codes = fn () => array_column($this->getJson(self::CLIENT."/{$uuid}/preview")->assertOk()->json('data.issues'), 'code');

        // A big budget (ten times the smallest allowed) in two days.
        $this->putJson(self::CLIENT."/{$uuid}", ['budget_type' => 'fixed', 'budget_min_minor' => 200000, 'duration_days' => 2])->assertOk();
        $this->assertSame(['duration_short_big_budget'], $codes());

        // A tiny one for most of a year.
        $this->putJson(self::CLIENT."/{$uuid}", ['budget_min_minor' => 15000, 'duration_days' => 240])->assertOk();
        $this->assertSame(['duration_long_small_budget'], $codes());

        // In between: nothing to say.
        $this->putJson(self::CLIENT."/{$uuid}", ['budget_min_minor' => 100000, 'duration_days' => 30])->assertOk();
        $this->assertSame([], $codes());
    }

    public function test_a_budget_over_the_countrys_limit_shows_in_the_preview_as_an_error(): void
    {
        FreelancePolicy::query()->create(['country_id' => $this->saudi->id, 'data' => ['project.max_budget_minor' => 400000], 'status' => true]);
        FreelancePolicies::forget();
        $uuid = $this->postJson(self::CLIENT."/{$this->project->uuid}/duplicate")->assertCreated()->json('data.uuid');

        $r = $this->getJson(self::CLIENT."/{$uuid}/preview")->assertOk()->assertJsonPath('data.can_submit', false);
        $this->assertSame('project_budget_above_max', $r->json('data.issues.0.code'));
        $this->assertSame('budget', $r->json('data.issues.0.field'));
        $this->assertSame('error', $r->json('data.issues.0.severity'));
    }

    // ------------------------------------------------------------------ FL-211

    public function test_each_project_says_what_is_due_from_the_client_next(): void
    {
        $next = fn (string $uuid) => $this->getJson(self::CLIENT."/{$uuid}")->assertOk()->json('data.next_action');

        // A half-written draft, then a complete one.
        $half = $this->postJson(self::CLIENT, ['title' => 'Logo'])->assertCreated()->json('data.uuid');
        $this->assertSame('finish_draft', $next($half));
        $copy = $this->postJson(self::CLIENT."/{$this->project->uuid}/duplicate")->assertCreated();
        $this->assertSame('submit', $copy->json('data.next_action'));

        // Published with an offer waiting.
        $this->assertSame('review_offers', $next($this->project->uuid));

        // Chosen: the agreement is a draft for the client to send.
        $this->choose();
        $this->assertSame('send_agreement', $next($this->project->uuid));
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();
        $this->assertSame(null, $next($this->project->uuid));

        // Sent back by the reviewer.
        $this->postJson(self::CLIENT."/{$copy->json('data.uuid')}/submit")->assertOk();
        FreelanceProject::query()->where('uuid', $copy->json('data.uuid'))->update(['status' => FreelanceProjectStatus::ChangesRequested]);
        $this->assertSame('fix_project', $next($copy->json('data.uuid')));
    }

    public function test_the_project_page_lists_all_the_parts_and_the_list_stays_light(): void
    {
        $this->inProgress();

        $page = $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertOk()
            ->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.next_action', 'fund_milestone')
            ->assertJsonCount(1, 'data.milestones')->assertJsonPath('data.milestones.0.status', 'unfunded')->assertJsonPath('data.milestones.0.amount_minor', 350000)
            ->assertJsonPath('data.milestones.0.position', 1)
            ->assertJsonPath('data.progress.total', 1)->assertJsonPath('data.progress.approved', 0)->assertJsonPath('data.progress.in_review', 0)
            ->assertJsonPath('data.progress.price_minor', 350000)->assertJsonPath('data.progress.approved_minor', 0);
        $this->assertNotEmpty($page->json('data.milestones.0.status_label'));

        // The list carries the numbers and the next step, not the parts themselves.
        $this->getJson(self::CLIENT)->assertOk()->assertJsonPath('data.0.progress.total', 1)->assertJsonPath('data.0.next_action', 'fund_milestone')->assertJsonPath('data.0.milestones', null);
    }

    public function test_a_delivered_part_is_waiting_for_the_client_and_an_approved_one_counts_as_done(): void
    {
        $this->inProgress();
        $part = FreelanceMilestone::query()->where('project_id', $this->project->id)->firstOrFail();

        $part->update(['status' => 'delivered']);
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertJsonPath('data.next_action', 'review_delivery')->assertJsonPath('data.progress.in_review', 1);

        $part->update(['status' => 'disputed']);
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertJsonPath('data.next_action', 'dispute');

        $part->update(['status' => 'in_progress']);
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertJsonPath('data.next_action', null);

        $part->update(['status' => 'approved']);
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertJsonPath('data.progress.approved', 1)->assertJsonPath('data.progress.approved_minor', 350000);
    }

    public function test_a_project_without_work_has_no_progress_or_parts(): void
    {
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertOk()->assertJsonPath('data.progress', null)->assertJsonPath('data.milestones', null);
    }
}
