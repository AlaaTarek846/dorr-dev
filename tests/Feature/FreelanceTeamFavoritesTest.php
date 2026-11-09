<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceProjectInvitation as Invitation;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FS-213 (owner 2026-11-08): a client keeps the freelancers' teams he worked with and invites one to a private project (the invitation is the
 * team's Owner's, who offers in its name); a member has a job title, shown on the team's profile next to his first name only when he agreed
 * to be shown (D10).
 */
class FreelanceTeamFavoritesTest extends TestCase
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

    private Freelancer $omar;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'teams', 'invitations'] as $feature) {
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
        $this->omar = $this->freelancer('+966500000095', 'Omar Khaled'); // not in the team
        $teams = app(FreelancerTeams::class);
        $this->team = $teams->create($this->sara, 'Dorr Studio');
        $teams->join($this->team, $this->lina, Member::PM);
        $teams->join($this->team, $this->noor);
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

    private function project(string $visibility = 'public', string $title = 'A booking website'): FreelanceProject
    {
        return FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => $title,
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => $visibility, 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
    }

    /** @param  array<string, mixed>  $over */
    private function offerBody(array $over = []): array
    {
        return $over + ['price_minor' => 350000, 'duration_days' => 21, 'details' => 'We built three booking sites like this; we will deliver in three steps with a demo each week.'];
    }

    /** Sara offers on the client's (public) project in the team's name - the private team is now open to him. */
    private function teamOffered(): FreelanceProject
    {
        $p = $this->project();
        $this->as($this->sara);
        $this->postJson(self::API."/projects/{$p->uuid}/offers", $this->offerBody(['team' => $this->team->uuid]))->assertCreated();

        return $p;
    }

    // ------------------------------------------------------------------------------------------------ favorites

    public function test_a_client_keeps_a_team_that_sent_him_an_offer_and_lists_it(): void
    {
        $url = self::CLIENT."/favorites/teams/{$this->team->uuid}";
        // A private team that never wrote to him: as if there were no team.
        $this->asClient();
        $this->postJson($url)->assertNotFound();

        $this->teamOffered();
        $this->asClient();
        $this->getJson(self::CLIENT."/freelancer-teams/{$this->team->uuid}")->assertOk()->assertJsonPath('data.team.is_favorite', false);
        $this->postJson($url)->assertOk()->assertJsonPath('data.is_favorite', true);
        $this->postJson($url)->assertOk(); // twice is once
        $this->assertSame(1, DB::table('freelance_client_team_favorites')->count());
        $this->getJson(self::CLIENT."/freelancer-teams/{$this->team->uuid}")->assertJsonPath('data.team.is_favorite', true);
        $this->getJson(self::CLIENT.'/favorites/teams')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $this->team->uuid)->assertJsonPath('data.0.name', 'Dorr Studio')
            ->assertJsonPath('data.0.worked_with', false)->assertJsonPath('data.0.accepting_projects', true)->assertJsonPath('data.0.completed_projects', 0);
        // The freelancers' list is its own.
        $this->getJson(self::CLIENT.'/favorites')->assertOk()->assertJsonCount(0, 'data');

        // Another client the team never wrote to can not keep it.
        $this->asClient($this->stranger);
        $this->postJson($url)->assertNotFound();
        $this->getJson(self::CLIENT.'/favorites/teams')->assertOk()->assertJsonCount(0, 'data');

        // A team that is not readable any more drops out of the list but stays saved, and can still be taken off.
        $this->team->forceFill(['status' => 'suspended'])->save();
        $this->asClient();
        $this->getJson(self::CLIENT.'/favorites/teams')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, DB::table('freelance_client_team_favorites')->count());
        $this->deleteJson($url)->assertOk()->assertJsonPath('data.is_favorite', false);
        $this->assertSame(0, DB::table('freelance_client_team_favorites')->count());
        $this->deleteJson($url)->assertNotFound();
    }

    public function test_a_public_team_can_be_kept_by_any_client(): void
    {
        $this->team->forceFill(['visibility' => FreelanceTeam::PUBLIC])->save();
        $this->asClient($this->stranger);
        $this->postJson(self::CLIENT."/favorites/teams/{$this->team->uuid}")->assertOk();
        $this->getJson(self::CLIENT.'/favorites/teams')->assertOk()->assertJsonCount(1, 'data');
    }

    // ------------------------------------------------------------------------------------------------ invitations

    public function test_a_client_invites_a_team_to_a_private_project_and_its_owner_offers_in_its_name(): void
    {
        $this->teamOffered();
        $private = $this->project('private', 'A private job');

        $this->asClient();
        $this->postJson(self::CLIENT."/projects/{$private->uuid}/invitations", ['teams' => [$this->team->uuid]])->assertCreated()
            ->assertJsonPath('data.result.invited', 1)->assertJsonPath('data.invitations.0.team.uuid', $this->team->uuid)->assertJsonPath('data.invitations.0.team.name', 'Dorr Studio')
            ->assertJsonPath('data.invitations.0.freelancer.name', 'Sara Ali');
        $row = Invitation::query()->firstOrFail();
        $this->assertSame($this->sara->id, $row->freelancer_id, 'the invitation is the team\'s Owner\'s');
        $this->assertSame($this->team->id, $row->team_id);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.invitation.team_received')->count());
        $this->assertSame(0, $this->lina->notifications()->count() + $this->noor->notifications()->count(), 'the other people are not told');

        // Again: already invited, nothing more.
        $this->postJson(self::CLIENT."/projects/{$private->uuid}/invitations", ['teams' => [$this->team->uuid]])->assertCreated()
            ->assertJsonPath('data.result.invited', 0)->assertJsonPath('data.result.skipped.0.reason', 'already_invited');

        // The Owner sees it as his team's, opens the project and offers in the team's name - which says yes.
        $this->as($this->sara);
        $this->getJson(self::API.'/invitations')->assertOk()->assertJsonPath('data.0.team.uuid', $this->team->uuid)->assertJsonPath('data.0.can.offer', true);
        $this->getJson(self::API."/projects/{$private->uuid}")->assertOk();
        $this->postJson(self::API."/projects/{$private->uuid}/offers", $this->offerBody(['team' => $this->team->uuid]))->assertCreated()->assertJsonPath('data.team.uuid', $this->team->uuid);
        $this->assertSame(Invitation::ACCEPTED, $row->refresh()->status);

        // A PM of the team was not invited himself: the private project stays closed to him.
        $this->as($this->lina);
        $this->getJson(self::API."/projects/{$private->uuid}")->assertNotFound();
    }

    public function test_a_team_is_invited_only_when_the_client_may_read_it_and_it_takes_new_projects(): void
    {
        $private = $this->project('private', 'A private job');
        $this->asClient();
        // A private team that never wrote to him.
        $this->postJson(self::CLIENT."/projects/{$private->uuid}/invitations", ['teams' => [$this->team->uuid]])->assertCreated()
            ->assertJsonPath('data.result.invited', 0)->assertJsonPath('data.result.skipped.0.reason', 'not_found');

        $this->teamOffered();
        $this->team->forceFill(['accepting_projects' => false])->save();
        $this->asClient();
        $this->postJson(self::CLIENT."/projects/{$private->uuid}/invitations", ['teams' => [$this->team->uuid]])->assertCreated()
            ->assertJsonPath('data.result.invited', 0)->assertJsonPath('data.result.skipped.0.reason', 'team_not_accepting');
        $this->assertSame(0, Invitation::query()->count());

        // Nothing to invite at all is refused; freelancers and teams go together.
        $this->postJson(self::CLIENT."/projects/{$private->uuid}/invitations", [])->assertUnprocessable();
        $this->team->forceFill(['accepting_projects' => true])->save();
        $this->postJson(self::CLIENT."/projects/{$private->uuid}/invitations", ['teams' => [$this->team->uuid], 'freelancers' => [$this->omar->uuid]])->assertCreated()
            ->assertJsonPath('data.result.invited', 2);
        $this->assertSame([null, $this->team->id], Invitation::query()->orderBy('freelancer_id', 'desc')->pluck('team_id')->map(fn ($id) => $id === null ? null : (int) $id)->all());

        // A public project takes no invitations, a team's no more than a person's.
        $public = $this->project('public', 'An open job');
        $this->postJson(self::CLIENT."/projects/{$public->uuid}/invitations", ['teams' => [$this->team->uuid]])->assertUnprocessable()
            ->assertJsonPath('error_code', 'freelance_invitation_project_public');
    }

    // ------------------------------------------------------------------------------------------------ job titles

    public function test_an_owner_writes_a_job_title_and_a_person_his_own(): void
    {
        $url = fn (Freelancer $f) => self::API."/teams/{$this->team->uuid}/members/{$f->uuid}/title";

        $this->as($this->sara);
        $this->putJson($url($this->noor), ['job_title' => '  <b>UI designer</b> '])->assertOk()->assertJsonPath('data.member.job_title', 'UI designer');
        $this->getJson(self::API."/teams/{$this->team->uuid}/members")->assertOk()->assertJsonPath('data.members.2.job_title', 'UI designer');
        $this->putJson($url($this->sara), ['job_title' => 'Lead developer'])->assertOk();

        // A person writes his own; a PM or a member does not write somebody else's.
        $this->as($this->noor);
        $this->putJson($url($this->noor), ['job_title' => 'UI & UX designer'])->assertOk()->assertJsonPath('data.member.job_title', 'UI & UX designer');
        $this->putJson($url($this->lina), ['job_title' => 'Nothing'])->assertForbidden();
        $this->as($this->lina);
        $this->putJson($url($this->noor), ['job_title' => 'Nothing'])->assertForbidden();
        $this->assertSame('UI & UX designer', Member::query()->where('freelancer_id', $this->noor->id)->value('job_title'));

        // Too long is refused; empty clears it; somebody outside the team is not found.
        $this->putJson($url($this->lina), ['job_title' => str_repeat('a', 61)])->assertUnprocessable();
        $this->putJson($url($this->lina), ['job_title' => ''])->assertOk()->assertJsonPath('data.member.job_title', null);
        $this->putJson($url($this->lina), ['job_title' => 'PM'])->assertOk();
        $this->putJson($url($this->lina), [])->assertOk()->assertJsonPath('data.member.job_title', null); // an app that leaves the null out
        $this->as($this->sara);
        $this->putJson($url($this->omar), ['job_title' => 'x'])->assertNotFound();
        $this->as($this->omar);
        $this->putJson($url($this->noor), ['job_title' => 'x'])->assertNotFound();

        // Every change is on the record with who made it.
        $rows = AuditLog::query()->where('action', 'freelance.team.job_title_changed')->get();
        $this->assertCount(5, $rows);
        $this->assertSame($this->sara->id, (int) $rows->first()->after['by_freelancer_id']);

        // A suspended team changes nothing.
        $this->team->forceFill(['status' => 'suspended'])->save();
        $this->as($this->noor);
        $this->putJson($url($this->noor), ['job_title' => 'Designer'])->assertStatus(409);
    }

    public function test_the_profile_shows_the_job_title_of_a_person_who_agreed_to_be_shown_only(): void
    {
        Member::query()->where('freelancer_id', $this->noor->id)->update(['job_title' => 'UI designer']);
        Member::query()->where('freelancer_id', $this->lina->id)->update(['job_title' => 'Project manager']);
        $this->as($this->noor);
        $this->putJson(self::API."/teams/{$this->team->uuid}/profile/me", ['show_in_profile' => true])->assertOk();
        $this->teamOffered();

        $this->asClient();
        $res = $this->getJson(self::CLIENT."/freelancer-teams/{$this->team->uuid}")->assertOk()
            ->assertJsonPath('data.team.members_count', 3)->assertJsonCount(1, 'data.team.members')
            ->assertJsonPath('data.team.members.0.name', 'Noor')->assertJsonPath('data.team.members.0.job_title', 'UI designer')->assertJsonPath('data.team.members.0.role', 'member');
        $this->assertStringNotContainsString('Project manager', $res->getContent(), 'a person who did not agree is not shown, nor his title');

        // Inside the team everybody is listed with his title.
        $this->as($this->lina);
        $this->getJson(self::API."/teams/{$this->team->uuid}/profile")->assertOk()->assertJsonPath('data.team.me.job_title', 'Project manager')
            ->assertJsonPath('data.team.members.1.job_title', 'Project manager');
    }
}
