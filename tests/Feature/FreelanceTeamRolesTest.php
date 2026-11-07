<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Http\Controllers\Freelancer\TeamMemberController;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\FreelancerTeam\TeamAbilities as A;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Tests\TestCase;

/**
 * FS-204: the roles of a freelancers' team - the Owner may do everything and alone sends the delivery to the client (D11, never a setting);
 * a PM and a member start from their role's defaults, and the Owner switches four abilities on or off for each of them (cleared when the
 * role changes); the team's people see who is in it and what each may do; every change of the switches is on the audit log.
 */
class FreelanceTeamRolesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/freelance/v1/teams';

    private Country $saudi;

    private FreelanceTeam $team;

    private Freelancer $sara;

    private Freelancer $lina;

    private Freelancer $noor;

    private Freelancer $huda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'teams'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $this->sara = $this->freelancer('+966500000091', 'Sara');
        $this->lina = $this->freelancer('+966500000092', 'Lina');
        $this->noor = $this->freelancer('+966500000093', 'Noor');
        $this->huda = $this->freelancer('+966500000094', 'Huda');

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

    private function can(Freelancer $who, string $ability): bool
    {
        return app(A::class)->can($this->team->refresh(), $who, $ability);
    }

    private function set(Freelancer $by, Freelancer $target, array $permissions)
    {
        $this->as($by);

        return $this->putJson(self::URL."/{$this->team->uuid}/members/{$target->uuid}/permissions", ['permissions' => $permissions]);
    }

    public function test_each_role_starts_with_its_defaults(): void
    {
        foreach (A::ALL as $ability) {
            $this->assertTrue($this->can($this->sara, $ability), "Owner: {$ability}");
        }
        // A PM sees the price, talks to the client and keeps the team's files, but does not invite and never does an Owner's work.
        $this->assertTrue($this->can($this->lina, A::VIEW_PRICE));
        $this->assertTrue($this->can($this->lina, A::CLIENT_CHAT));
        $this->assertTrue($this->can($this->lina, A::INTERNAL_FILES));
        $this->assertFalse($this->can($this->lina, A::INVITE_MEMBERS));
        // A member starts with every switch off.
        foreach (A::SWITCHES as $ability) {
            $this->assertFalse($this->can($this->noor, $ability), "Member: {$ability}");
        }
        foreach ([$this->lina, $this->noor] as $who) {
            foreach (A::OWNER_ONLY as $ability) {
                $this->assertFalse($this->can($who, $ability));
            }
            foreach (A::EVERYONE as $ability) {
                $this->assertTrue($this->can($who, $ability));
            }
        }
        // Somebody not in the team, and an unknown ability: no.
        $this->assertFalse($this->can($this->huda, A::INTERNAL_CHAT));
        $this->assertFalse($this->can($this->sara, 'fly'));
    }

    public function test_only_the_owner_delivers_whatever_is_set_d11(): void
    {
        // `deliver` is not a switch: the API refuses it, for a PM and a member alike, and nothing is saved.
        foreach ([$this->lina, $this->noor] as $who) {
            $this->set($this->sara, $who, [A::DELIVER => true])->assertStatus(422)->assertJsonPath('error_code', 'freelance_freelancer_team_permission_invalid');
        }
        $this->assertSame(0, Member::query()->whereNotNull('permissions')->count());

        // Even a row written by hand does not give it.
        Member::query()->where('freelancer_id', $this->lina->id)->update(['permissions' => json_encode([A::DELIVER => true, A::MANAGE_MEMBERS => true])]);
        $this->assertFalse($this->can($this->lina, A::DELIVER));
        $this->assertFalse($this->can($this->lina, A::MANAGE_MEMBERS));
        $this->assertTrue($this->can($this->sara, A::DELIVER));

        // A second Owner may deliver too - it is the role, not one person.
        app(FreelancerTeams::class)->changeRole($this->team, $this->sara, $this->lina, Member::OWNER);
        $this->assertTrue($this->can($this->lina, A::DELIVER));

        // The check the delivery path will call (FS-205/206) answers 403.
        try {
            app(A::class)->assert($this->team->refresh(), $this->noor, A::DELIVER);
            $this->fail('A member delivered');
        } catch (\Modules\Freelance\Exceptions\FreelanceException $e) {
            $this->assertSame('freelancer_team_ability', $e->errorCode);
            $this->assertSame(403, $e->apiStatus());
        }
    }

    public function test_the_owner_switches_abilities_for_a_member_and_it_is_audited(): void
    {
        $this->set($this->sara, $this->noor, [A::VIEW_PRICE => true, A::INTERNAL_FILES => true])->assertOk()
            ->assertJsonPath('data.member.role', 'member')
            ->assertJsonPath('data.member.abilities.view_price', true)->assertJsonPath('data.member.abilities.internal_files', true)
            ->assertJsonPath('data.member.abilities.client_chat', false)->assertJsonPath('data.member.abilities.deliver', false)
            ->assertJsonPath('data.member.custom.view_price', true);
        $this->assertTrue($this->can($this->noor, A::VIEW_PRICE));

        // Only what is named changes; turning one back to the role's default is not kept.
        $this->set($this->sara, $this->noor, [A::VIEW_PRICE => false])->assertOk()->assertJsonPath('data.member.abilities.internal_files', true);
        $this->assertSame([A::INTERNAL_FILES => true], Member::query()->where('freelancer_id', $this->noor->id)->value('permissions'));

        // A PM's default can be switched off too.
        $this->set($this->sara, $this->lina, [A::CLIENT_CHAT => false, A::INVITE_MEMBERS => true])->assertOk();
        $this->assertFalse($this->can($this->lina, A::CLIENT_CHAT));
        $this->assertTrue($this->can($this->lina, A::INVITE_MEMBERS));

        $logs = AuditLog::query()->where('action', TeamMemberController::PERMISSIONS_CHANGED)->orderBy('id')->get();
        $this->assertCount(3, $logs);
        $this->assertFalse($logs[0]->before[A::VIEW_PRICE]);
        $this->assertTrue($logs[0]->after[A::VIEW_PRICE]);
        $this->assertSame($this->sara->id, $logs[0]->after['by_freelancer_id']);

        // Saving the same values again writes nothing new to the log.
        $this->set($this->sara, $this->noor, [A::INTERNAL_FILES => true])->assertOk();
        $this->assertSame(3, AuditLog::query()->where('action', TeamMemberController::PERMISSIONS_CHANGED)->count());
    }

    public function test_only_an_owner_sets_switches_and_never_on_an_owner(): void
    {
        $this->set($this->lina, $this->noor, [A::VIEW_PRICE => true])->assertForbidden()->assertJsonPath('error_code', 'freelance_freelancer_team_forbidden');
        $this->set($this->noor, $this->noor, [A::VIEW_PRICE => true])->assertForbidden();
        $this->set($this->sara, $this->sara, [A::VIEW_PRICE => false])->assertStatus(422)->assertJsonPath('error_code', 'freelance_freelancer_team_permissions_owner');
        // Not a boolean, not a switch, nothing named.
        $this->set($this->sara, $this->noor, [A::VIEW_PRICE => 'yes'])->assertStatus(422);
        $this->set($this->sara, $this->noor, ['fly' => true])->assertStatus(422)->assertJsonPath('error_code', 'freelance_freelancer_team_permission_invalid');
        $this->set($this->sara, $this->noor, [])->assertStatus(422);
        // A person who is not in the team (or left it) as the target: 404.
        $this->set($this->sara, $this->huda, [A::VIEW_PRICE => true])->assertNotFound();
        $this->assertSame(0, Member::query()->whereNotNull('permissions')->count());
    }

    public function test_switches_are_cleared_when_the_role_changes_or_the_person_comes_back(): void
    {
        $this->set($this->sara, $this->noor, [A::VIEW_PRICE => true])->assertOk();
        $teams = app(FreelancerTeams::class);
        $teams->changeRole($this->team, $this->sara, $this->noor, Member::PM);
        $this->assertNull(Member::query()->where('freelancer_id', $this->noor->id)->value('permissions'));
        $this->assertFalse($this->can($this->noor, A::INVITE_MEMBERS));

        $this->set($this->sara, $this->noor, [A::INVITE_MEMBERS => true])->assertOk();
        $teams->leave($this->team, $this->noor);
        $this->assertFalse($this->can($this->noor, A::INTERNAL_CHAT));
        $teams->join($this->team, $this->noor);
        $this->assertFalse($this->can($this->noor, A::INVITE_MEMBERS));
        $this->assertFalse($this->can($this->noor, A::VIEW_PRICE));
    }

    public function test_a_suspended_team_gives_nobody_anything_and_takes_no_change(): void
    {
        $this->team->forceFill(['status' => FreelanceTeam::SUSPENDED])->save();
        $this->assertFalse($this->can($this->sara, A::DELIVER));
        $this->assertFalse($this->can($this->lina, A::INTERNAL_CHAT));
        $this->set($this->sara, $this->noor, [A::VIEW_PRICE => true])->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_inactive');
    }

    public function test_an_owner_changes_a_role_from_the_app_and_it_is_audited(): void
    {
        $role = function (Freelancer $by, Freelancer $target, string $role) {
            $this->as($by);

            return $this->putJson(self::URL."/{$this->team->uuid}/members/{$target->uuid}/role", ['role' => $role]);
        };

        // Switches set for the old role are cleared with the change (FS-204); passing ownership on.
        $this->set($this->sara, $this->noor, [A::VIEW_PRICE => true])->assertOk();
        $role($this->sara, $this->noor, Member::PM)->assertOk()->assertJsonPath('data.member.role', 'pm')->assertJsonPath('data.member.custom', []);
        $role($this->sara, $this->lina, Member::OWNER)->assertOk()->assertJsonPath('data.member.role', 'owner')->assertJsonPath('data.member.abilities.deliver', true);
        $this->assertTrue($this->can($this->lina, A::DELIVER));

        // The same role again writes nothing to the log.
        $role($this->sara, $this->lina, Member::OWNER)->assertOk();
        $logs = AuditLog::query()->where('action', TeamMemberController::ROLE_CHANGED)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('member', $logs[0]->before['role']);
        $this->assertSame('pm', $logs[0]->after['role']);
        $this->assertSame($this->sara->id, $logs[0]->after['by_freelancer_id']);

        // Only an Owner; a role that does not exist; somebody who is not in the team.
        $role($this->noor, $this->lina, Member::MEMBER)->assertForbidden()->assertJsonPath('error_code', 'freelance_freelancer_team_forbidden');
        $role($this->sara, $this->noor, 'boss')->assertStatus(422);
        $role($this->sara, $this->huda, Member::PM)->assertNotFound();
    }

    public function test_the_last_owner_can_not_step_down_from_the_app(): void
    {
        $this->as($this->sara);
        $this->putJson(self::URL."/{$this->team->uuid}/members/{$this->sara->uuid}/role", ['role' => Member::PM])
            ->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_last_owner');
        $this->assertSame(Member::OWNER, Member::query()->where('freelancer_id', $this->sara->id)->value('role'));
    }

    public function test_my_teams_list_carries_what_a_card_shows(): void
    {
        $this->team->forceFill(['accepting_projects' => true, 'visibility' => FreelanceTeam::PUBLIC, 'verified_at' => now()])->save();

        $this->as($this->noor);
        $this->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data.teams')
            ->assertJsonPath('data.teams.0.uuid', $this->team->uuid)
            ->assertJsonPath('data.teams.0.role', 'member')
            ->assertJsonPath('data.teams.0.members_count', 3)
            ->assertJsonPath('data.teams.0.verified', true)
            ->assertJsonPath('data.teams.0.accepting_projects', true)
            ->assertJsonPath('data.teams.0.visibility', 'public')
            ->assertJsonPath('data.teams.0.logo_url', null);
    }

    public function test_the_teams_people_see_who_is_in_it_and_what_each_may_do(): void
    {
        $this->set($this->sara, $this->noor, [A::CLIENT_CHAT => true])->assertOk();

        $this->as($this->noor);
        $this->getJson(self::URL."/{$this->team->uuid}/members")->assertOk()
            ->assertJsonCount(3, 'data.members')
            ->assertJsonPath('data.members.0.role', 'owner')->assertJsonPath('data.members.0.freelancer.name', 'Sara')->assertJsonPath('data.members.0.custom', null)
            ->assertJsonPath('data.members.1.role', 'pm')->assertJsonPath('data.members.2.role', 'member')
            ->assertJsonPath('data.members.2.abilities.client_chat', true)
            ->assertJsonPath('data.me.role', 'member')->assertJsonPath('data.me.abilities.deliver', false)
            ->assertJsonPath('data.switches', A::SWITCHES);

        // Somebody not in the team: the team looks like it does not exist; with the switch off: 403.
        $this->as($this->huda);
        $this->getJson(self::URL."/{$this->team->uuid}/members")->assertNotFound();
        FreelanceFeatureRule::query()->where('feature', 'teams')->delete();
        FreelanceFeatures::forget();
        $this->as($this->sara);
        $this->getJson(self::URL."/{$this->team->uuid}/members")->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
    }
}
