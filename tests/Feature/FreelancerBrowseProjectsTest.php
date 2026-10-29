<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Catalog\FreelanceSkills;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\User\Models\User;
use Tests\TestCase;

/** FL-206 / FL-207: a freelancer browses the published public projects of their own country — filtered, one page each, saved — and sees nothing else. */
class FreelancerBrowseProjectsTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1/projects';

    private Country $saudi;

    private Country $egypt;

    private FreelanceSpecialty $dev;

    private FreelanceSpecialty $web;

    private FreelanceSpecialty $design;

    private User $client;

    private Freelancer $me;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = $this->country('SA', '+966', 'SAR');
        $this->egypt = $this->country('EG', '+20', 'EGP');
        FreelanceFeatures::forget();

        $this->dev = $this->specialty('Development', null);
        $this->web = $this->specialty('Web', $this->dev->id);
        $this->design = $this->specialty('Design', null);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->me = $this->freelancer('+966500000091', $this->saudi);
    }

    private function country(string $code, string $dial, string $currency): Country
    {
        $c = Country::create(['code' => $code, 'dial_code' => $dial, 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => strtolower($code)])->id, 'currency_id' => Currency::create(['code' => $currency, 'symbol' => $currency])->id]);
        foreach (['freelance', 'projects'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $c->id, 'enabled' => true]);
        }

        return $c;
    }

    private function specialty(string $name, ?int $parent): FreelanceSpecialty
    {
        $s = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1, 'parent_id' => $parent]);
        $s->translations()->createMany([['locale' => 'en', 'name' => $name], ['locale' => 'ar', 'name' => $name]]);

        return $s;
    }

    private function freelancer(string $phone, Country $country, string $status = 'verified'): Freelancer
    {
        $f = Freelancer::query()->create(['name' => 'Sara', 'phone' => $phone, 'country_id' => $country->id, 'phone_verified_at' => now(), 'status' => $status]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f, [], 'freelance_api');

        return $f;
    }

    private function project(array $o = []): FreelanceProject
    {
        $p = FreelanceProject::query()->create($o + [
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id,
            'title' => 'A booking website', 'description' => 'A small site where patients pick a doctor and a time.',
            'budget_type' => 'range', 'budget_min_minor' => 300000, 'budget_max_minor' => 500000, 'currency_code' => 'SAR', 'duration_days' => 30,
            'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(),
        ]);
        $skill = FreelanceSkill::query()->firstOrCreate(['slug' => 'laravel'], ['status' => FreelanceSkill::ACTIVE]);
        $skill->translations()->firstOrCreate(['locale' => 'en'], ['name' => 'Laravel', 'normalized' => FreelanceSkills::normalize('Laravel')]);
        $p->skills()->sync([$skill->id]);

        return $p;
    }

    public function test_only_published_public_projects_of_my_country_are_listed(): void
    {
        $mine = $this->project();
        $this->project(['title' => 'Still in review', 'status' => FreelanceProjectStatus::PendingReview]);
        $this->project(['title' => 'A draft', 'status' => FreelanceProjectStatus::Draft]);
        $this->project(['title' => 'Cancelled one', 'status' => FreelanceProjectStatus::Cancelled]);
        $this->project(['title' => 'Private work', 'visibility' => 'private']);
        $this->project(['title' => 'In Egypt', 'country_id' => $this->egypt->id, 'currency_code' => 'EGP']);

        $this->getJson(self::API)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $mine->uuid)
            ->assertJsonPath('data.0.skills.0.name', 'Laravel')->assertJsonPath('data.0.specialty.name', 'Web')->assertJsonPath('data.0.budget.max_minor', 500000)->assertJsonPath('data.0.is_favorite', false);
    }

    public function test_the_client_and_the_files_are_never_shown_and_others_projects_are_not_found(): void
    {
        $mine = $this->project();
        $this->getJson(self::API."/{$mine->uuid}")->assertOk()->assertJsonPath('data.description', $mine->description)->assertJsonPath('data.attachments_count', 0)
            ->assertJsonMissingPath('data.client')->assertJsonMissingPath('data.attachments');
        $this->assertStringNotContainsString('Mona', $this->getJson(self::API)->getContent());
        $this->assertStringNotContainsString('+966500000001', $this->getJson(self::API."/{$mine->uuid}")->getContent());

        foreach ([
            $this->project(['status' => FreelanceProjectStatus::PendingReview]),
            $this->project(['visibility' => 'private']),
            $this->project(['country_id' => $this->egypt->id]),
        ] as $hidden) {
            $this->getJson(self::API."/{$hidden->uuid}")->assertNotFound();
            $this->postJson(self::API."/{$hidden->uuid}/favorite")->assertNotFound();
        }
    }

    public function test_the_list_filters_by_specialty_budget_duration_and_text(): void
    {
        $web = $this->project(['title' => 'Booking website', 'budget_min_minor' => 300000, 'budget_max_minor' => 500000, 'duration_days' => 30]);
        $logo = $this->project(['title' => 'Logo for a bakery', 'freelance_specialty_id' => $this->design->id, 'budget_type' => 'fixed', 'budget_min_minor' => 50000, 'budget_max_minor' => null, 'duration_days' => 7]);

        // A parent specialty finds the work of its children.
        $this->getJson(self::API."?specialty_id={$this->dev->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $web->uuid);
        $this->getJson(self::API."?specialty_id={$this->design->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $logo->uuid);

        // Budget: a project fits when its range overlaps what is asked.
        $this->getJson(self::API.'?budget_min_minor=400000')->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $web->uuid);
        $this->getJson(self::API.'?budget_max_minor=100000')->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $logo->uuid);
        $this->getJson(self::API.'?budget_min_minor=60000&budget_max_minor=290000')->assertJsonCount(0, 'data');
        $this->getJson(self::API.'?budget_min_minor=1&budget_max_minor=9000000')->assertJsonCount(2, 'data');

        $this->getJson(self::API.'?duration_max_days=10')->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $logo->uuid);
        $this->getJson(self::API.'?q=bakery')->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $logo->uuid);
        $this->getJson(self::API.'?q=nothing-at-all')->assertJsonCount(0, 'data');
        $this->getJson(self::API.'?duration_max_days=0')->assertUnprocessable();
    }

    public function test_a_freelancer_with_specialties_sees_theirs_by_default_and_can_widen_it(): void
    {
        $web = $this->project(['title' => 'Booking website']);
        $logo = $this->project(['title' => 'Logo', 'freelance_specialty_id' => $this->design->id]);

        // No specialties yet: everything.
        $this->getJson(self::API)->assertJsonCount(2, 'data')->assertJsonPath('pagination.scope', 'all')->assertJsonPath('pagination.has_specialties', false);
        $this->getJson(self::API.'?scope=mine')->assertJsonCount(2, 'data');

        // Chose the parent (Development): its sub-specialty Web counts, Design does not.
        $this->me->specialties()->attach($this->dev->id, ['is_primary' => true]);
        $this->getJson(self::API)->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $web->uuid)->assertJsonPath('pagination.scope', 'mine')->assertJsonPath('pagination.has_specialties', true);
        $this->getJson(self::API.'?scope=all')->assertJsonCount(2, 'data')->assertJsonPath('pagination.scope', 'all');

        // A specialty picked in the filter wins; saved projects ignore the default.
        $this->getJson(self::API."?specialty_id={$this->design->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $logo->uuid);
        $this->postJson(self::API."/{$logo->uuid}/favorite")->assertOk();
        $this->getJson(self::API.'?favorites=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $logo->uuid);

        // The sub-specialty chosen alone does not pull in its parent's other work.
        $this->me->specialties()->sync([$this->web->id => ['is_primary' => true]]);
        $this->getJson(self::API)->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $web->uuid);
        $this->getJson(self::API.'?scope=bad')->assertUnprocessable();
    }

    public function test_the_newest_come_first_in_pages(): void
    {
        $old = $this->project(['title' => 'Old', 'published_at' => now()->subDays(5)]);
        $new = $this->project(['title' => 'New', 'published_at' => now()->subHour()]);
        $mid = $this->project(['title' => 'Mid', 'published_at' => now()->subDays(2)]);

        $this->getJson(self::API.'?per_page=2')->assertOk()->assertJsonPath('data.0.uuid', $new->uuid)->assertJsonPath('data.1.uuid', $mid->uuid)->assertJsonPath('pagination.has_more_pages', true);
        $this->getJson(self::API.'?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $old->uuid);
    }

    public function test_saving_a_project_is_private_to_me_and_listed_under_favorites(): void
    {
        $a = $this->project(['title' => 'First']);
        $b = $this->project(['title' => 'Second']);

        $this->postJson(self::API."/{$a->uuid}/favorite")->assertOk()->assertJsonPath('data.is_favorite', true);
        $this->postJson(self::API."/{$a->uuid}/favorite")->assertOk()->assertJsonPath('data.is_favorite', true); // again: still one
        $this->assertSame(1, $this->me->projectFavorites()->count());

        $this->getJson(self::API)->assertJsonPath('data.1.is_favorite', true)->assertJsonPath('data.0.is_favorite', false);
        $this->getJson(self::API.'?favorites=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $a->uuid);

        // Another freelancer has their own list.
        $this->freelancer('+966500000092', $this->saudi);
        $this->getJson(self::API."/{$a->uuid}")->assertJsonPath('data.is_favorite', false);
        $this->getJson(self::API.'?favorites=1')->assertJsonCount(0, 'data');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->me->refresh(), [], 'freelance_api');
        $this->deleteJson(self::API."/{$a->uuid}/favorite")->assertOk()->assertJsonPath('data.is_favorite', false);
        $this->getJson(self::API.'?favorites=1')->assertJsonCount(0, 'data');
        $this->assertNotNull($b);
    }

    public function test_a_saved_project_that_stops_being_open_leaves_the_list(): void
    {
        $p = $this->project();
        $this->postJson(self::API."/{$p->uuid}/favorite")->assertOk();
        $p->update(['status' => FreelanceProjectStatus::Cancelled]);

        $this->getJson(self::API.'?favorites=1')->assertJsonCount(0, 'data');
        $this->getJson(self::API."/{$p->uuid}")->assertNotFound();
    }

    public function test_an_unverified_freelancer_can_look_and_the_switch_closes_it(): void
    {
        $this->project();
        $this->freelancer('+966500000093', $this->saudi, 'profile_ready');
        $this->getJson(self::API)->assertOk()->assertJsonCount(1, 'data');

        FreelanceFeatureRule::query()->where('feature', 'projects')->where('country_id', $this->saudi->id)->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->getJson(self::API)->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
    }

    public function test_a_signed_out_caller_is_refused(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson(self::API)->assertUnauthorized();
    }
}
