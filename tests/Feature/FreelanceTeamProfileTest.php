<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Catalog\FreelanceSkills;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FS-202a: a freelancers' team's profile. Only an Owner writes it; the team's people always read it, outsiders only an active team - a public
 * one, or a private one that sent the client an offer. A member is shown outside only with his own consent and by first name (D10); the
 * specialties, skills and languages are the members' (D10) and the figures the team's (D3). The team's works are its own, never the Owner's.
 */
class FreelanceTeamProfileTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance';

    private Country $saudi;

    private FreelanceSpecialty $web;

    private User $client;

    private User $stranger;

    private FreelanceTeam $team;

    private Freelancer $sara;

    private Freelancer $lina;

    private Freelancer $noor;

    private Freelancer $huda;

    private Freelancer $omar;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'teams'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->stranger = User::query()->create(['name' => 'Kamal', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);

        $this->sara = $this->freelancer('+966500000091', 'Sara Ali');
        $this->lina = $this->freelancer('+966500000092', 'Lina Hassan');
        $this->noor = $this->freelancer('+966500000093', 'Noor Said');
        $this->huda = $this->freelancer('+966500000094', 'Huda Omar');
        $this->omar = $this->freelancer('+966500000095', 'Omar Khaled'); // not in the team
        $teams = app(FreelancerTeams::class);
        $this->team = $teams->create($this->sara, 'Dorr Studio');
        $teams->join($this->team, $this->lina, Member::PM);
        $teams->join($this->team, $this->noor);
        $teams->join($this->team, $this->huda);
    }

    private function freelancer(string $phone, string $name): Freelancer
    {
        return Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
    }

    private function as(Freelancer $who): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($who->refresh(), [], 'freelance_api');
    }

    private function asClient(?User $who = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(($who ?? $this->client)->refresh(), [], 'user_api');
    }

    private function profile(): string
    {
        return self::API."/teams/{$this->team->uuid}/profile";
    }

    private function clientProfile(): string
    {
        return self::CLIENT."/freelancer-teams/{$this->team->uuid}";
    }

    private function edit(Freelancer $who, array $data): TestResponse
    {
        $this->as($who);

        return $this->putJson($this->profile(), $data);
    }

    /** Sara sends the team's offer on the client's project; returns the project. */
    private function offer(): FreelanceProject
    {
        $p = FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $this->as($this->sara);
        $this->postJson(self::API."/projects/{$p->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'We built three booking sites like this; we will deliver in three steps with a demo each week.',
            'team' => $this->team->uuid, 'staff' => [['freelancer' => $this->lina->uuid, 'role' => 'pm']],
        ])->assertCreated();

        return $p;
    }

    public function test_a_verified_freelancer_makes_a_team_and_only_an_owner_writes_its_profile(): void
    {
        $this->as($this->omar);
        $this->postJson(self::API.'/teams', ['name' => '  Omar   <b>Lab</b> '])->assertCreated()
            ->assertJsonPath('data.team.name', 'Omar Lab')->assertJsonPath('data.team.me.role', 'owner')->assertJsonPath('data.team.me.can_edit', true)
            ->assertJsonPath('data.team.visibility', 'private')->assertJsonPath('data.team.accepting_projects', true)->assertJsonPath('data.team.verified', false);
        $this->postJson(self::API.'/teams', ['name' => 'Second'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_freelancer_team_limit_owned'); // D1: one team owned

        $this->edit($this->sara, ['bio' => "<script>x</script>We build booking sites.\nFast.", 'visibility' => 'public', 'accepting_projects' => false])->assertOk()
            ->assertJsonPath('data.team.bio', "xWe build booking sites.\nFast.")->assertJsonPath('data.team.visibility', 'public')->assertJsonPath('data.team.accepting_projects', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'freelance.team.profile_updated']);
        $this->edit($this->sara, ['visibility' => 'everyone'])->assertStatus(422);
        $this->edit($this->sara, ['name' => 'ab'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_freelancer_team_name_required');

        // A PM or a member can not; somebody outside the team does not even find it.
        $this->edit($this->lina, ['bio' => 'Mine'])->assertForbidden()->assertJsonPath('error_code', 'freelance_freelancer_team_ability');
        $this->edit($this->noor, ['bio' => 'Mine'])->assertForbidden();
        $this->edit($this->omar, ['bio' => 'Mine'])->assertNotFound();
        $this->getJson($this->profile())->assertOk(); // ...but reads a public team
        $this->as($this->lina);
        $this->getJson($this->profile())->assertOk()->assertJsonPath('data.team.me.role', 'pm')->assertJsonPath('data.team.me.can_edit', false);

        // A suspended team changes nothing.
        $this->team->update(['status' => FreelanceTeam::SUSPENDED]);
        $this->edit($this->sara, ['bio' => 'Again'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_inactive');
        $this->assertSame("xWe build booking sites.\nFast.", $this->team->refresh()->bio);
    }

    public function test_a_private_team_is_read_only_by_its_people_and_a_client_it_sent_an_offer_to(): void
    {
        $this->asClient();
        $this->getJson($this->clientProfile())->assertNotFound();
        $this->as($this->omar);
        $this->getJson($this->profile())->assertNotFound();

        $this->offer();
        $this->asClient();
        $this->getJson($this->clientProfile())->assertOk()->assertJsonPath('data.team.name', 'Dorr Studio')->assertJsonPath('data.team.members_count', 4)
            ->assertJsonMissingPath('data.team.visibility')->assertJsonMissingPath('data.team.me')->assertJsonMissingPath('data.team.status');
        $this->asClient($this->stranger);
        $this->getJson($this->clientProfile())->assertNotFound();

        // Public: any signed-in client or freelancer.
        $this->edit($this->sara, ['visibility' => 'public'])->assertOk();
        $this->asClient($this->stranger);
        $this->getJson($this->clientProfile())->assertOk();
        $this->as($this->omar);
        $this->getJson($this->profile())->assertOk()->assertJsonMissingPath('data.team.me');

        // Suspended: hidden outside, still the team's own.
        $this->team->update(['status' => FreelanceTeam::SUSPENDED]);
        $this->asClient();
        $this->getJson($this->clientProfile())->assertNotFound();
        $this->as($this->omar);
        $this->getJson($this->profile())->assertNotFound();
        $this->as($this->noor);
        $this->getJson($this->profile())->assertOk()->assertJsonPath('data.team.status', 'suspended');
    }

    public function test_a_person_of_the_clients_project_team_reads_a_private_team_when_his_role_reads_the_offers(): void
    {
        $p = $this->offer();
        $manager = User::query()->create(['name' => 'Rana', 'phone' => '+966500000003', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $reader = User::query()->create(['name' => 'Salma', 'phone' => '+966500000004', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $invited = User::query()->create(['name' => 'Dina', 'phone' => '+966500000005', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        foreach ([[$manager, 'manager', 'active'], [$reader, 'collaborator', 'active'], [$invited, 'manager', 'invited']] as [$user, $role, $status]) {
            \Modules\Freelance\Models\FreelanceProjectMember::query()->create(['project_id' => $p->id, 'user_id' => $user->id, 'role' => $role, 'status' => $status]);
        }

        $this->asClient($manager);
        $this->getJson($this->clientProfile())->assertOk()->assertJsonPath('data.team.name', 'Dorr Studio');
        $this->asClient($reader); // a collaborator does not read the offers, so not the team behind one
        $this->getJson($this->clientProfile())->assertNotFound();
        $this->asClient($invited); // an invitation not accepted yet is not a place in the team
        $this->getJson($this->clientProfile())->assertNotFound();
    }

    public function test_a_member_is_shown_outside_the_team_only_with_his_own_consent_and_by_first_name(): void
    {
        $this->edit($this->sara, ['visibility' => 'public'])->assertOk();
        $this->asClient();
        $this->getJson($this->clientProfile())->assertOk()->assertJsonCount(0, 'data.team.members')->assertJsonPath('data.team.members_count', 4);

        $this->as($this->noor);
        $this->putJson($this->profile().'/me', ['show_in_profile' => true])->assertOk()->assertJsonPath('data.show_in_profile', true);
        $this->as($this->omar); // outside the team: no place to agree in
        $this->putJson($this->profile().'/me', ['show_in_profile' => true])->assertNotFound();

        $this->asClient();
        $this->getJson($this->clientProfile())->assertOk()->assertJsonCount(1, 'data.team.members')
            ->assertJsonPath('data.team.members.0.name', 'Noor')->assertJsonPath('data.team.members.0.role', 'member')
            ->assertJsonMissingPath('data.team.members.0.freelancer');
        $this->assertFalse((bool) Member::query()->where('freelancer_id', $this->lina->id)->value('show_in_profile'));

        // The team's people see everybody, with each one's choice.
        $this->as($this->lina);
        $this->getJson($this->profile())->assertOk()->assertJsonCount(4, 'data.team.members')
            ->assertJsonPath('data.team.members.0.name', 'Sara Ali')->assertJsonPath('data.team.members.0.show_in_profile', false)
            ->assertJsonPath('data.team.members.2.name', 'Noor Said')->assertJsonPath('data.team.members.2.show_in_profile', true);

        // Taking it back hides him again; a suspended team still lets him (his own data).
        $this->team->update(['status' => FreelanceTeam::SUSPENDED]);
        $this->as($this->noor);
        $this->putJson($this->profile().'/me', ['show_in_profile' => false])->assertOk();
        $this->team->update(['status' => FreelanceTeam::ACTIVE]);
        $this->asClient();
        $this->getJson($this->clientProfile())->assertOk()->assertJsonCount(0, 'data.team.members');
    }

    public function test_the_profile_gathers_the_members_specialties_skills_and_languages_and_shows_the_teams_own_figures(): void
    {
        $design = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 2]);
        $design->translations()->createMany([['locale' => 'en', 'name' => 'Design'], ['locale' => 'ar', 'name' => 'تصميم']]);
        $laravel = FreelanceSkill::query()->create(['slug' => 'laravel', 'status' => FreelanceSkill::ACTIVE]);
        $laravel->translations()->create(['locale' => 'en', 'name' => 'Laravel', 'normalized' => FreelanceSkills::normalize('Laravel')]);
        $this->sara->specialties()->attach($this->web->id, ['is_primary' => true]);
        $this->noor->specialties()->attach($this->web->id, ['is_primary' => true]);
        $this->lina->specialties()->attach($design->id, ['is_primary' => true]);
        $this->omar->specialties()->attach($design->id, ['is_primary' => true]); // not in the team
        $this->noor->skills()->attach($laravel->id, ['level' => 'expert']);
        $this->sara->forceFill(['contact_languages' => ['ar', 'en']])->save();
        $this->noor->forceFill(['contact_languages' => ['en', 'fr']])->save();

        $this->as($this->huda);
        $this->getJson($this->profile())->assertOk()
            ->assertJsonCount(2, 'data.team.specialties')->assertJsonPath('data.team.specialties.0.id', $this->web->id)->assertJsonPath('data.team.specialties.1.id', $design->id)
            ->assertJsonCount(1, 'data.team.skills')->assertJsonPath('data.team.skills.0.name', 'Laravel')
            ->assertJsonPath('data.team.languages', ['ar', 'en', 'fr'])
            ->assertJsonPath('data.team.stats.reviews_count', 0)->assertJsonPath('data.team.stats.completed_projects', 0)->assertJsonPath('data.team.stats.rating', null)
            ->assertJsonMissingPath('data.team.stats.similar_projects')->assertJsonPath('data.team.reviews', [])->assertJsonPath('data.team.running_projects', 0);

        // Somebody who leaves takes his specialty with him.
        app(FreelancerTeams::class)->leave($this->team, $this->lina);
        $this->getJson($this->profile())->assertOk()->assertJsonCount(1, 'data.team.specialties');
    }

    public function test_the_teams_works_are_its_own_never_the_owners_and_shown_by_their_visibility(): void
    {
        $this->as($this->sara);
        $work = ['title' => 'Clinic booking', 'description' => 'Patients pick a doctor.', 'visibility' => 'clients_only', 'rights_confirmed' => true];
        $id = $this->postJson(self::API."/teams/{$this->team->uuid}/portfolio", $work)->assertCreated()->assertJsonPath('data.title', 'Clinic booking')->json('data.id');
        $this->postJson(self::API."/teams/{$this->team->uuid}/portfolio", ['title' => 'Shop', 'visibility' => 'public'])->assertStatus(422)
            ->assertJsonPath('error_code', 'freelance_portfolio_rights_required');
        $this->assertDatabaseHas('freelancer_portfolio_items', ['id' => $id, 'team_id' => $this->team->id, 'freelancer_id' => $this->sara->id]);

        // Not Sara's own: her portfolio and her public works do not list it.
        $this->getJson(self::API.'/profile/portfolio')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::API."/public/freelancers/{$this->sara->uuid}/portfolio")->assertOk()->assertJsonCount(0, 'data');
        $this->putJson(self::API."/profile/portfolio/{$id}", ['title' => 'Mine now'])->assertNotFound();

        // The team's people read it; only an Owner writes.
        $this->as($this->noor);
        $this->getJson(self::API."/teams/{$this->team->uuid}/portfolio")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.visibility', 'clients_only');
        $this->as($this->lina);
        $this->postJson(self::API."/teams/{$this->team->uuid}/portfolio", $work)->assertForbidden();
        $this->putJson(self::API."/teams/{$this->team->uuid}/portfolio/{$id}", ['title' => 'PM edit'])->assertForbidden();
        $this->as($this->omar);
        $this->getJson(self::API."/teams/{$this->team->uuid}/portfolio")->assertNotFound();

        // Outside: a client sees the clients-only work, a freelancer the public ones only; a hidden one nobody.
        $this->edit($this->sara, ['visibility' => 'public'])->assertOk();
        $this->asClient($this->stranger);
        $this->getJson($this->clientProfile())->assertOk()->assertJsonCount(1, 'data.team.portfolio')->assertJsonPath('data.team.portfolio.0.title', 'Clinic booking')
            ->assertJsonPath('data.team.portfolio.0.id', null);
        $this->as($this->omar);
        $this->getJson($this->profile())->assertOk()->assertJsonCount(0, 'data.team.portfolio');
        DB::table('freelancer_portfolio_items')->where('id', $id)->update(['hidden_by_admin_at' => now()]);
        $this->asClient($this->stranger);
        $this->getJson($this->clientProfile())->assertOk()->assertJsonCount(0, 'data.team.portfolio');

        $this->as($this->sara);
        $this->deleteJson(self::API."/teams/{$this->team->uuid}/portfolio/{$id}")->assertOk();
        $this->assertDatabaseMissing('freelancer_portfolio_items', ['id' => $id]);
    }

    public function test_the_logo_and_a_teams_offer_show_the_teams_summary_to_the_client(): void
    {
        Storage::fake('public');
        $this->as($this->noor);
        $this->postJson($this->profile().'/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 300)])->assertForbidden();
        $this->as($this->sara);
        $url = $this->postJson($this->profile().'/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 300)])->assertOk()->json('data.logo_url');
        $this->assertNotNull($url);
        $this->assertDatabaseHas('audit_logs', ['action' => 'freelance.team.logo_changed']);

        $p = $this->offer();
        $this->asClient();
        $this->getJson(self::CLIENT."/projects/{$p->uuid}/offers")->assertOk()
            ->assertJsonPath('data.0.team.name', 'Dorr Studio')->assertJsonPath('data.0.team.profile.logo_url', $url)
            ->assertJsonPath('data.0.team.profile.verified', false)->assertJsonPath('data.0.team.profile.completed_projects', 0)
            ->assertJsonPath('data.0.team.profile.accepting_projects', true)->assertJsonPath('data.0.team.profile.rating', null);

        $this->as($this->sara);
        $this->deleteJson($this->profile().'/logo')->assertOk()->assertJsonPath('data.logo_url', null);
        $this->assertNull($this->team->refresh()->logoUrl());
    }
}
