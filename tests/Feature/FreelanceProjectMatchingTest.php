<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceProjectAlert;
use Modules\Freelance\Models\FreelanceProjectInvitation;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Catalog\FreelanceSkills;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Projects\ProjectInvitations;
use Modules\Freelance\Services\Projects\ProjectMatching;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-208: when a public project opens, the freelancers it suits are told once — by plain rules (country, specialty, skills, being
 * open to offers, the guide price) — in the app, live and by push; nobody is told twice or about what they were invited to.
 */
class FreelanceProjectMatchingTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Country $egypt;

    private FreelanceSpecialty $dev;

    private FreelanceSpecialty $web;

    private FreelanceSpecialty $design;

    private FreelanceSkill $laravel;

    private FreelanceSkill $vue;

    private User $client;

    private int $phones = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = $this->country('SA', '+966', 'SAR');
        $this->egypt = $this->country('EG', '+20', 'EGP');
        FreelanceFeatures::forget();
        FreelancePolicies::forget();

        $this->dev = $this->specialty('Development', null);
        $this->web = $this->specialty('Web', $this->dev->id);
        $this->design = $this->specialty('Design', null);
        $this->laravel = $this->skill('laravel', 'Laravel');
        $this->vue = $this->skill('vue', 'Vue');
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
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

    private function skill(string $slug, string $name): FreelanceSkill
    {
        $s = FreelanceSkill::query()->create(['slug' => $slug, 'status' => FreelanceSkill::ACTIVE]);
        $s->translations()->create(['locale' => 'en', 'name' => $name, 'normalized' => FreelanceSkills::normalize($name)]);

        return $s;
    }

    /** A verified freelancer of Saudi Arabia who works in Web by default. */
    private function freelancer(array $o = [], array $specialties = [], array $skills = []): Freelancer
    {
        $f = Freelancer::query()->create($o + [
            'name' => 'Freelancer '.(++$this->phones), 'phone' => '+96650001'.str_pad((string) $this->phones, 4, '0', STR_PAD_LEFT),
            'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified', 'locale' => 'en',
        ]);
        $f->specialties()->sync($specialties === [] ? [$this->web->id] : $specialties);
        $f->skills()->sync(array_map(fn ($s) => $s->id, $skills));

        return $f;
    }

    private function project(array $o = [], ?array $skills = null): FreelanceProject
    {
        $p = FreelanceProject::query()->create($o + [
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id,
            'title' => 'A booking website', 'description' => 'A small site where patients pick a doctor and a time.',
            'budget_type' => 'range', 'budget_min_minor' => 300000, 'budget_max_minor' => 500000, 'currency_code' => 'SAR', 'duration_days' => 30,
            'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(),
        ]);
        $p->skills()->sync(array_map(fn ($s) => $s->id, $skills ?? [$this->laravel]));

        return $p;
    }

    /** @return list<int> */
    private function matched(FreelanceProject $p): array
    {
        return app(ProjectMatching::class)->matches($p)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_the_country_the_specialty_and_the_account_state_decide_who_fits(): void
    {
        $own = $this->freelancer();
        $parent = $this->freelancer(specialties: [$this->dev->id]);
        $design = $this->freelancer(specialties: [$this->design->id]);
        $abroad = $this->freelancer(['country_id' => $this->egypt->id]);
        $unverified = $this->freelancer(['status' => 'profile_ready']);
        $suspended = $this->freelancer(['status' => 'suspended']);
        $noSpecialty = $this->freelancer();
        $noSpecialty->specialties()->sync([]);

        $ids = $this->matched($this->project());

        // The freelancer's own specialty, and a freelancer whose specialty sits above the project's (as the project list shows them).
        $this->assertEqualsCanonicalizing([$own->id, $parent->id], $ids);
        $this->assertNotContains($design->id, $ids);
        $this->assertNotContains($abroad->id, $ids);
        $this->assertNotContains($unverified->id, $ids);
        $this->assertNotContains($suspended->id, $ids);
        $this->assertNotContains($noSpecialty->id, $ids);
    }

    public function test_skills_must_be_shared_when_both_sides_have_them_and_the_best_fit_comes_first(): void
    {
        $project = $this->project(skills: [$this->laravel, $this->vue]);
        $both = $this->freelancer(skills: [$this->laravel, $this->vue]);
        $one = $this->freelancer(skills: [$this->laravel]);
        $other = $this->freelancer(skills: [$this->skill('photoshop', 'Photoshop')]);
        $none = $this->freelancer();

        $ids = $this->matched($project);

        $this->assertSame($both->id, $ids[0]);
        $this->assertSame($one->id, $ids[1]);
        $this->assertContains($none->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertSame(2, (int) app(ProjectMatching::class)->matches($project)->first()->overlap);
    }

    public function test_a_paused_freelancer_or_one_on_time_off_is_left_alone_until_they_are_back(): void
    {
        $paused = $this->freelancer();
        $paused->availability()->create(['accepting_requests' => false, 'paused_until' => now()->addDays(3)->toDateString()]);
        $pausedForGood = $this->freelancer();
        $pausedForGood->availability()->create(['accepting_requests' => false]);
        $back = $this->freelancer();
        $back->availability()->create(['accepting_requests' => false, 'paused_until' => now()->subDay()->toDateString()]);
        $away = $this->freelancer();
        $away->timeOff()->create(['starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDays(5)->toDateString()]);
        $later = $this->freelancer();
        $later->timeOff()->create(['starts_on' => now()->addDays(10)->toDateString(), 'ends_on' => now()->addDays(15)->toDateString()]);
        $open = $this->freelancer();
        $open->availability()->create(['accepting_requests' => true]);

        $this->assertEqualsCanonicalizing([$back->id, $later->id, $open->id], $this->matched($this->project()));
    }

    public function test_a_fixed_guide_price_must_meet_the_budget_but_hourly_and_unset_ones_do_not(): void
    {
        $project = $this->project(); // 3000.00 – 5000.00
        $inside = $this->freelancer();
        $inside->availability()->create(['rate_type' => 'fixed', 'rate_min_minor' => 400000, 'rate_max_minor' => 900000]);
        $tooDear = $this->freelancer();
        $tooDear->availability()->create(['rate_type' => 'fixed', 'rate_min_minor' => 600000, 'rate_max_minor' => 900000]);
        $tooCheap = $this->freelancer();
        $tooCheap->availability()->create(['rate_type' => 'fixed', 'rate_min_minor' => 10000, 'rate_max_minor' => 200000]);
        $onlyFloor = $this->freelancer();
        $onlyFloor->availability()->create(['rate_type' => 'fixed', 'rate_min_minor' => 100000]);
        $hourly = $this->freelancer();
        $hourly->availability()->create(['rate_type' => 'hourly', 'rate_min_minor' => 9000000, 'rate_max_minor' => 9500000]);
        $unset = $this->freelancer();
        $unset->availability()->create(['rate_type' => 'none']);

        $this->assertEqualsCanonicalizing([$inside->id, $onlyFloor->id, $hourly->id, $unset->id], $this->matched($project));

        // A fixed budget is a range of one number.
        $fixed = $this->project(['budget_type' => 'fixed', 'budget_min_minor' => 700000, 'budget_max_minor' => null]);
        $ids = $this->matched($fixed);
        $this->assertContains($inside->id, $ids);
        $this->assertContains($tooDear->id, $ids);
        $this->assertNotContains($tooCheap->id, $ids);
    }

    public function test_alerting_tells_each_freelancer_once_in_the_app_and_records_it(): void
    {
        $sara = $this->freelancer(skills: [$this->laravel]);
        $project = $this->project();

        $this->assertSame(1, app(ProjectMatching::class)->alert($project));
        $this->assertSame(0, app(ProjectMatching::class)->alert($project));

        $notice = $sara->notifications()->get();
        $this->assertCount(1, $notice);
        $this->assertSame('freelance.project.matched', $notice->first()->data['event']);
        $this->assertSame($project->uuid, $notice->first()->data['data']['project']);
        $this->assertSame(1, FreelanceProjectAlert::query()->where('project_id', $project->id)->where('freelancer_id', $sara->id)->value('score'));
    }

    public function test_those_invited_or_who_already_offered_are_not_told_and_private_or_unpublished_projects_are_not_announced(): void
    {
        $invited = $this->freelancer();
        $offered = $this->freelancer();
        $fresh = $this->freelancer();
        $project = $this->project();
        FreelanceProjectInvitation::query()->create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'project_id' => $project->id, 'freelancer_id' => $invited->id, 'client_id' => $this->client->id, 'status' => 'declined', 'invited_at' => now()]);
        FreelanceOffer::query()->create(['project_id' => $project->id, 'freelancer_id' => $offered->id, 'price_minor' => 400000, 'currency_code' => 'SAR', 'duration_days' => 20, 'details' => 'I can do it well and fast.', 'status' => 'submitted']);

        $this->assertSame([$fresh->id], $this->matched($project));

        $private = $this->project(['visibility' => 'private']);
        $this->assertSame(0, app(ProjectMatching::class)->alert($private));
        $review = $this->project(['status' => FreelanceProjectStatus::PendingReview]);
        $this->assertSame(0, app(ProjectMatching::class)->alert($review));
        $this->assertSame(0, FreelanceProjectAlert::query()->whereIn('project_id', [$private->id, $review->id])->count());
    }

    public function test_only_the_best_fits_up_to_the_limit_are_told(): void
    {
        config(['freelance.matching.max_recipients' => 2]);
        $best = $this->freelancer(skills: [$this->laravel, $this->vue]);
        $good = $this->freelancer(skills: [$this->laravel]);
        $this->freelancer();

        $this->assertSame([$best->id, $good->id], $this->matched($this->project(skills: [$this->laravel, $this->vue])));
    }

    public function test_publishing_from_the_admin_tells_the_matching_freelancers(): void
    {
        $sara = $this->freelancer(skills: [$this->laravel]);
        $other = $this->freelancer(specialties: [$this->design->id]);
        $project = $this->project(['status' => FreelanceProjectStatus::PendingReview, 'published_at' => null]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach (['freelance-projects.view', 'freelance-projects.update'] as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo(['freelance-projects.view', 'freelance-projects.update']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');

        $this->postJson("/api/admin/v1/freelance-projects/{$project->uuid}/publish")->assertOk();

        $this->assertSame(['freelance.project.matched'], $sara->notifications()->get()->map(fn ($n) => $n->data['event'])->all());
        $this->assertSame(0, $other->notifications()->count());
        // The client still gets their own notice.
        $this->assertSame('freelance.project.published', $this->client->notifications()->first()->data['event']);
    }

    public function test_a_private_project_made_public_tells_the_matching_freelancers_who_were_not_invited(): void
    {
        $invited = $this->freelancer();
        $sara = $this->freelancer();
        $project = $this->project(['visibility' => 'private']);
        FreelanceProjectInvitation::query()->create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'project_id' => $project->id, 'freelancer_id' => $invited->id, 'client_id' => $this->client->id, 'status' => 'sent', 'invited_at' => now()]);

        app(ProjectInvitations::class)->makePublic($project, $this->client);
        app(DeferredCallbackCollection::class)->invoke(); // what the end of a request does

        $this->assertSame(1, $sara->notifications()->count());
        $this->assertSame(0, $invited->notifications()->count());
    }
}
