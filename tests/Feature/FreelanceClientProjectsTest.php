<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Services\Catalog\FreelanceSkills;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-201 / FL-202: a client writes a project as a draft, sends it for review only when complete and within the budget limits of
 * their country and the specialty, can take it back or cancel it, and only ever touches their own projects.
 */
class FreelanceClientProjectsTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/mobile/v1/freelance/projects';

    private Country $saudi;

    private FreelanceSpecialty $web;

    private FreelanceSkill $laravel;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();

        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web development'], ['locale' => 'ar', 'name' => 'تطوير مواقع']]);
        $this->laravel = FreelanceSkill::query()->create(['slug' => 'laravel', 'status' => FreelanceSkill::ACTIVE]);
        $this->laravel->translations()->create(['locale' => 'en', 'name' => 'Laravel', 'normalized' => FreelanceSkills::normalize('Laravel')]);

        $this->client('+966500000001');
    }

    private function client(string $phone): User
    {
        $user = User::query()->create(['name' => 'Mona', 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user, [], 'user_api');

        return $user;
    }

    /** @return array<string, mixed> */
    private function complete(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'A booking website for my clinic',
            'description' => 'A small site where patients pick a doctor, a day and a time, and get a reminder.',
            'specialty_id' => $this->web->id,
            'skill_ids' => [$this->laravel->id],
            'budget_type' => 'range',
            'budget_min_minor' => 300000,
            'budget_max_minor' => 500000,
            'duration_days' => 30,
            'visibility' => 'public',
        ];
    }

    private function draft(array $data = []): string
    {
        return $this->postJson(self::API, $data === [] ? $this->complete() : $data)->assertCreated()->json('data.uuid');
    }

    public function test_a_half_done_draft_is_saved_and_says_what_is_missing(): void
    {
        $this->postJson(self::API, ['title' => 'Logo'])->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.budget.currency', 'SAR')
            ->assertJsonPath('data.missing', ['title', 'description', 'specialty', 'skills', 'budget', 'duration'])
            ->assertJsonPath('data.can.delete', true)->assertJsonPath('data.timeline.0.type', 'project.created');

        $uuid = $this->getJson(self::API)->assertOk()->assertJsonCount(1, 'data')->json('data.0.uuid');
        $this->putJson(self::API."/{$uuid}", $this->complete())->assertOk()->assertJsonPath('data.missing', [])
            ->assertJsonPath('data.specialty.name', 'Web development')->assertJsonPath('data.skills.0.name', 'Laravel')->assertJsonPath('data.budget.max_minor', 500000);

        // A fixed budget drops the top of the range.
        $this->putJson(self::API."/{$uuid}", ['budget_type' => 'fixed', 'budget_min_minor' => 400000])->assertOk()->assertJsonPath('data.budget.max_minor', null);
    }

    public function test_an_incomplete_project_cannot_be_sent_and_a_complete_one_goes_to_review(): void
    {
        $half = $this->draft(['title' => 'Logo']);
        $this->postJson(self::API."/{$half}/submit")->assertUnprocessable()->assertJsonPath('error_code', 'freelance_project_incomplete')
            ->assertJsonPath('data.fields', ['title', 'description', 'specialty', 'skills', 'budget', 'duration']);

        $uuid = $this->draft();
        $this->postJson(self::API."/{$uuid}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.can.edit', false)->assertJsonPath('data.can.withdraw', true)->assertJsonPath('data.timeline.1.type', 'project.submitted');

        // Under review it can't be changed, sent again or deleted.
        $this->putJson(self::API."/{$uuid}", ['title' => 'Something else entirely'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_project_invalid_state');
        $this->postJson(self::API."/{$uuid}/submit")->assertStatus(409);
        $this->deleteJson(self::API."/{$uuid}")->assertStatus(409);

        // Taken back: a draft again, editable, and sent once more.
        $this->postJson(self::API."/{$uuid}/withdraw")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->putJson(self::API."/{$uuid}", ['duration_days' => 45])->assertOk();
        $this->postJson(self::API."/{$uuid}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');

        $project = FreelanceProject::query()->where('uuid', $uuid)->first();
        // Three status moves (creating a draft is not one).
        $this->assertSame(3, $project->version);
        $this->assertSame(['project.created', 'project.submitted', 'project.withdrawn', 'project.submitted'], $project->events()->orderBy('id')->pluck('type')->all());
    }

    public function test_the_budget_follows_the_country_and_the_specialty_minimum_and_maximum(): void
    {
        $policies = app(FreelancePolicies::class);
        $policies->save($this->saudi->id, null, ['project.min_budget_minor' => 100000, 'project.max_budget_minor' => 1000000]);
        $policies->save($this->saudi->id, $this->web->id, ['project.min_budget_minor' => 350000]);

        // The specialty's own minimum wins over the country's.
        $this->getJson(self::API."/budget-rules?specialty_id={$this->web->id}")->assertOk()->assertJsonPath('data.min_minor', 350000)->assertJsonPath('data.max_minor', 1000000)->assertJsonPath('data.currency', 'SAR');
        $this->getJson(self::API.'/budget-rules')->assertOk()->assertJsonPath('data.min_minor', 100000);

        $low = $this->draft($this->complete(['budget_min_minor' => 300000]));
        $this->postJson(self::API."/{$low}/submit")->assertUnprocessable()->assertJsonPath('error_code', 'freelance_project_budget_below_min')->assertJsonPath('data.min_minor', 350000);

        $high = $this->draft($this->complete(['budget_min_minor' => 400000, 'budget_max_minor' => 2000000]));
        $this->postJson(self::API."/{$high}/submit")->assertUnprocessable()->assertJsonPath('error_code', 'freelance_project_budget_above_max');

        $upside = $this->draft($this->complete(['budget_min_minor' => 500000, 'budget_max_minor' => 400000]));
        $this->postJson(self::API."/{$upside}/submit")->assertUnprocessable()->assertJsonPath('error_code', 'freelance_project_budget_range');

        $fine = $this->draft($this->complete(['budget_min_minor' => 400000, 'budget_max_minor' => 900000]));
        $this->postJson(self::API."/{$fine}/submit")->assertOk();
    }

    public function test_files_are_added_listed_downloaded_by_the_owner_and_removed(): void
    {
        $uuid = $this->draft();
        $media = $this->post(self::API."/{$uuid}/attachments", ['file' => UploadedFile::fake()->createWithContent('brief.pdf', "%PDF-1.4\n%%EOF")], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.attachments.0.name', fn ($n) => str_ends_with($n, '.pdf'))->json('data.attachments.0.id');
        $this->post(self::API."/{$uuid}/attachments", ['file' => UploadedFile::fake()->create('virus.exe', 10)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->get(self::API."/{$uuid}/attachments/{$media}")->assertOk();

        // Another client sees none of it.
        $this->client('+966500000002');
        $this->getJson(self::API."/{$uuid}")->assertNotFound();
        $this->get(self::API."/{$uuid}/attachments/{$media}")->assertNotFound();
        $this->getJson(self::API)->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson(self::API."/{$uuid}/attachments/{$media}")->assertNotFound();

        // Its owner removes it.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::query()->where('phone', '+966500000001')->first(), [], 'user_api');
        $this->deleteJson(self::API."/{$uuid}/attachments/{$media}")->assertOk()->assertJsonCount(0, 'data.attachments');
    }

    public function test_cancelling_a_sent_project_keeps_it_and_a_draft_is_deleted(): void
    {
        $sent = $this->draft();
        $this->postJson(self::API."/{$sent}/submit")->assertOk();
        $this->postJson(self::API."/{$sent}/cancel", ['reason' => 'Found someone'])->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.can.cancel', false);
        $this->postJson(self::API."/{$sent}/withdraw")->assertStatus(409);

        $draft = $this->draft(['title' => 'Logo']);
        $this->postJson(self::API."/{$draft}/cancel")->assertStatus(409);
        $this->deleteJson(self::API."/{$draft}")->assertOk();
        $this->assertDatabaseMissing('freelance_projects', ['uuid' => $draft]);
        $this->getJson(self::API.'?status=cancelled')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_bad_choices_are_refused(): void
    {
        $off = FreelanceSkill::query()->create(['slug' => 'off', 'status' => 'pending']);
        $this->postJson(self::API, ['skill_ids' => [$off->id]])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_profile_skill_invalid');
        $this->postJson(self::API, ['specialty_id' => 9999])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_profile_specialty_invalid');
        $this->postJson(self::API, ['budget_type' => 'hourly'])->assertUnprocessable();
        $this->postJson(self::API, ['duration_days' => 0])->assertUnprocessable();
        $this->postJson(self::API, ['visibility' => 'secret'])->assertUnprocessable();
    }

    public function test_open_projects_are_capped(): void
    {
        config(['freelance.projects.max_open' => 2]);
        $this->draft(['title' => 'One']);
        $this->draft(['title' => 'Two']);
        $this->postJson(self::API, ['title' => 'Three'])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_project_limit');
    }

    public function test_projects_are_closed_where_the_switch_is_off(): void
    {
        FreelanceFeatureRule::query()->where('feature', 'projects')->update(['enabled' => false]);
        FreelanceFeatures::forget();

        $this->getJson(self::API)->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
        $this->postJson(self::API, ['title' => 'Logo'])->assertForbidden();
    }
}
