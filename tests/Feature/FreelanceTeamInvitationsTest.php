<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamInvitation as Invitation;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\FreelancerTeam\TeamAbilities as A;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Tests\TestCase;

/**
 * FS-203: people come into a freelancers' team by invitation - to one freelancer by phone (from any country) or by a link used once; the
 * Owner, or a PM with the invite switch, sends them (a PM only invites members); the invited person accepts or declines, and accepting goes
 * through the team's own rules (a refusal leaves the invitation open); an invitation lasts 7 days; leaving and removing are at once; each
 * step is on the audit log and the people concerned are told.
 */
class FreelanceTeamInvitationsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/freelance/v1';

    private Country $saudi;

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
        $egypt = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'status' => true, 'flag_id' => Flag::create(['code' => 'eg'])->id, 'currency_id' => Currency::create(['code' => 'EGP', 'symbol' => 'EGP'])->id]);
        foreach (['freelance', 'teams'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $egypt->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $this->sara = $this->freelancer('+966500000091', 'Sara Ali', $this->saudi);
        $this->lina = $this->freelancer('+966500000092', 'Lina', $this->saudi);
        $this->noor = $this->freelancer('+966500000093', 'Noor', $this->saudi);
        $this->huda = $this->freelancer('+966500000094', 'Huda', $this->saudi);
        // Somebody in another country than the team's.
        $this->omar = $this->freelancer('+201000000001', 'Omar Hassan', $egypt);

        $teams = app(FreelancerTeams::class);
        $this->team = $teams->create($this->sara, 'Dorr Studio');
        $teams->join($this->team, $this->lina, Member::PM);
        $teams->join($this->team, $this->noor);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function freelancer(string $phone, string $name, Country $country, string $status = 'verified'): Freelancer
    {
        return Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $country->id, 'phone_verified_at' => now(), 'status' => $status]);
    }

    private function as(Freelancer $who): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($who->refresh(), [], 'freelance_api');
    }

    private function invite(Freelancer $by, ?string $phone, string $role = 'member')
    {
        $this->as($by);

        return $this->postJson(self::URL."/teams/{$this->team->uuid}/invitations", array_filter(['phone' => $phone, 'role' => $role]));
    }

    private function told(Freelancer $who, string $event): int
    {
        return $who->notifications()->where('data->event', $event)->count();
    }

    private function logged(string $action): int
    {
        return AuditLog::query()->where('action', $action)->count();
    }

    public function test_the_owner_invites_a_freelancer_from_another_country_who_accepts(): void
    {
        $r = $this->invite($this->sara, '+201000000001', 'member')->assertCreated()
            ->assertJsonPath('data.invitation.invited.name', 'Omar Hassan')->assertJsonPath('data.invitation.role', 'member')
            ->assertJsonPath('data.invitation.status', 'pending')->assertJsonPath('data.invitation.by_link', false)->assertJsonMissingPath('data.invitation.token');
        $uuid = $r->json('data.invitation.uuid');
        $this->assertSame(1, $this->told($this->omar, 'freelance.team_invited'));
        $this->assertSame(1, $this->logged('freelance.team.invited'));

        $this->as($this->omar);
        $this->getJson(self::URL.'/team-invitations')->assertOk()->assertJsonCount(1, 'data.invitations')
            ->assertJsonPath('data.invitations.0.team.name', 'Dorr Studio')->assertJsonPath('data.invitations.0.invited_by.name', 'Sara Ali');
        $this->postJson(self::URL."/team-invitations/{$uuid}/accept")->assertOk()->assertJsonPath('data.invitation.status', 'accepted');

        $row = Member::query()->where('team_id', $this->team->id)->where('freelancer_id', $this->omar->id)->firstOrFail();
        $this->assertSame([Member::MEMBER, Member::ACTIVE], [$row->role, $row->status]);
        $this->getJson(self::URL.'/team-invitations')->assertJsonCount(0, 'data.invitations');
        $this->assertSame(1, $this->told($this->sara, 'freelance.team_invitation_accepted'));
        $this->assertSame(1, $this->logged('freelance.team.invitation_accepted'));
        // It can not be answered twice.
        $this->postJson(self::URL."/team-invitations/{$uuid}/accept")->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_invitation_closed');
        $this->postJson(self::URL."/team-invitations/{$uuid}/decline")->assertStatus(409);
    }

    public function test_the_invited_person_can_decline_and_be_invited_again(): void
    {
        $uuid = $this->invite($this->sara, '966500000094', 'pm')->assertCreated()->json('data.invitation.uuid');
        // An open invitation is not doubled.
        $this->invite($this->sara, '+966500000094')->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_invite_open');

        $this->as($this->huda);
        $this->postJson(self::URL."/team-invitations/{$uuid}/decline")->assertOk()->assertJsonPath('data.invitation.status', 'declined');
        $this->assertFalse(Member::query()->where('freelancer_id', $this->huda->id)->exists());
        $this->assertSame(1, $this->told($this->sara, 'freelance.team_invitation_declined'));
        $this->assertSame(1, $this->logged('freelance.team.invitation_declined'));

        $this->invite($this->sara, '+966500000094', 'member')->assertCreated();
        // Somebody else can not answer it.
        $uuid2 = Invitation::query()->latest('id')->value('uuid');
        $this->as($this->noor);
        $this->postJson(self::URL."/team-invitations/{$uuid2}/accept")->assertNotFound();
    }

    public function test_who_may_invite_and_what_can_be_asked(): void
    {
        // A member with no switch, and somebody outside the team.
        $this->invite($this->noor, '+966500000094')->assertForbidden()->assertJsonPath('error_code', 'freelance_freelancer_team_ability');
        $this->as($this->huda);
        $this->postJson(self::URL."/teams/{$this->team->uuid}/invitations", ['role' => 'member'])->assertNotFound();
        // A PM does not invite until the Owner switches it on - and then only members, never a PM.
        $this->invite($this->lina, '+966500000094')->assertForbidden();
        $this->as($this->sara);
        $this->putJson(self::URL."/teams/{$this->team->uuid}/members/{$this->lina->uuid}/permissions", ['permissions' => [A::INVITE_MEMBERS => true]])->assertOk();
        $this->invite($this->lina, '+966500000094', 'pm')->assertForbidden()->assertJsonPath('error_code', 'freelance_freelancer_team_forbidden');
        $this->invite($this->lina, '+966500000094', 'member')->assertCreated();

        // The Owner is not an invited role; an unknown role is refused; so are an unknown phone, himself, a member already in.
        $this->invite($this->sara, '+966500000095', 'member')->assertStatus(422)->assertJsonPath('error_code', 'freelance_freelancer_team_invite_not_found');
        $this->invite($this->sara, '+966500000091')->assertStatus(422)->assertJsonPath('error_code', 'freelance_freelancer_team_invite_self');
        $this->invite($this->sara, '+966500000093')->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_already_member');
        $this->invite($this->sara, '+966500000094', 'owner')->assertStatus(422);
        // A blocked freelancer is not found.
        $this->freelancer('+966500000096', 'Blocked', $this->saudi, 'blocked');
        $this->invite($this->sara, '+966500000096')->assertStatus(422)->assertJsonPath('error_code', 'freelance_freelancer_team_invite_not_found');
        // A suspended team invites nobody.
        $this->team->forceFill(['status' => FreelanceTeam::SUSPENDED])->save();
        $this->invite($this->sara, '+201000000001')->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_inactive');
    }

    public function test_a_link_is_used_once_by_the_first_who_accepts(): void
    {
        $r = $this->invite($this->sara, null, 'member')->assertCreated()->assertJsonPath('data.invitation.by_link', true);
        $token = $r->json('data.invitation.token');
        $this->assertNotEmpty($token);

        // The people who may invite see the open link; the others do not see the list.
        $this->as($this->sara);
        $this->getJson(self::URL."/teams/{$this->team->uuid}/invitations")->assertOk()->assertJsonCount(1, 'data.invitations')->assertJsonPath('data.invitations.0.token', $token)->assertJsonPath('data.days', 7);
        $this->as($this->noor);
        $this->getJson(self::URL."/teams/{$this->team->uuid}/invitations")->assertForbidden();

        // He who sent it can not use it; a stranger sees which team it is for, then joins.
        $this->as($this->sara);
        $this->postJson(self::URL."/team-invitations/by-link/{$token}/accept")->assertStatus(422)->assertJsonPath('error_code', 'freelance_freelancer_team_invite_self');
        $this->as($this->omar);
        $this->getJson(self::URL."/team-invitations/by-link/{$token}")->assertOk()->assertJsonPath('data.invitation.team.name', 'Dorr Studio')->assertJsonPath('data.invitation.role', 'member');
        $this->postJson(self::URL."/team-invitations/by-link/{$token}/accept")->assertOk()->assertJsonPath('data.invitation.status', 'accepted');
        $this->assertTrue(app(FreelancerTeams::class)->isMember($this->team, $this->omar));
        $this->assertSame(1, $this->told($this->sara, 'freelance.team_invitation_accepted'));

        // Nobody after him.
        $this->as($this->huda);
        $this->postJson(self::URL."/team-invitations/by-link/{$token}/accept")->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_invitation_closed');
        $this->getJson(self::URL.'/team-invitations/by-link/nope')->assertNotFound();
        $this->as($this->sara);
        $this->getJson(self::URL."/teams/{$this->team->uuid}/invitations")->assertJsonCount(0, 'data.invitations');
    }

    public function test_an_invitation_lasts_seven_days(): void
    {
        $uuid = $this->invite($this->sara, '+201000000001')->assertCreated()->json('data.invitation.uuid');
        $token = $this->invite($this->sara, null)->assertCreated()->json('data.invitation.token');

        Carbon::setTestNow(now()->addDays(8));
        $this->as($this->omar);
        $this->getJson(self::URL.'/team-invitations')->assertJsonCount(0, 'data.invitations');
        $this->postJson(self::URL."/team-invitations/{$uuid}/accept")->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_invitation_closed');
        $this->getJson(self::URL."/team-invitations/by-link/{$token}")->assertStatus(409);
        $this->postJson(self::URL."/team-invitations/by-link/{$token}/accept")->assertStatus(409);
        $this->assertFalse(app(FreelancerTeams::class)->isMember($this->team, $this->omar));
        $this->as($this->sara);
        $this->getJson(self::URL."/teams/{$this->team->uuid}/invitations")->assertJsonCount(0, 'data.invitations');

        // A lapsed one does not stop a new invitation to the same person.
        $this->invite($this->sara, '+201000000001')->assertCreated();
    }

    public function test_a_refusal_by_the_teams_rules_leaves_the_invitation_open(): void
    {
        // Not verified yet: 403, and still waiting.
        $newbie = $this->freelancer('+966500000097', 'Newbie', $this->saudi, 'registered');
        $uuid = $this->invite($this->sara, '+966500000097')->assertCreated()->json('data.invitation.uuid');
        $this->as($newbie);
        $this->postJson(self::URL."/team-invitations/{$uuid}/accept")->assertForbidden()->assertJsonPath('error_code', 'freelance_verification_required');
        $this->getJson(self::URL.'/team-invitations')->assertJsonCount(1, 'data.invitations');
        $newbie->forceFill(['status' => 'verified'])->save();
        $this->postJson(self::URL."/team-invitations/{$uuid}/accept")->assertOk();

        // The team is full: 422 until the admin makes room.
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['teams.members_max' => 4]);
        FreelancePolicies::forget();
        $full = $this->invite($this->sara, '+201000000001')->assertCreated()->json('data.invitation.uuid');
        $this->as($this->omar);
        $this->postJson(self::URL."/team-invitations/{$full}/accept")->assertStatus(422)->assertJsonPath('error_code', 'freelance_freelancer_team_limit_members');
        $this->assertSame(Invitation::PENDING, Invitation::query()->where('uuid', $full)->value('status'));
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['teams.members_max' => 5]);
        FreelancePolicies::forget();
        $this->postJson(self::URL."/team-invitations/{$full}/accept")->assertOk();
    }

    public function test_the_owner_or_the_sender_takes_an_invitation_back(): void
    {
        $this->as($this->sara);
        $this->putJson(self::URL."/teams/{$this->team->uuid}/members/{$this->lina->uuid}/permissions", ['permissions' => [A::INVITE_MEMBERS => true]])->assertOk();
        $byLina = $this->invite($this->lina, '+966500000094')->assertCreated()->json('data.invitation.uuid');
        $byOwner = $this->invite($this->sara, '+201000000001')->assertCreated()->json('data.invitation.uuid');

        // A PM can not take back the Owner's; the Owner takes back anybody's; the sender takes back her own.
        $this->as($this->lina);
        $this->deleteJson(self::URL."/teams/{$this->team->uuid}/invitations/{$byOwner}")->assertForbidden()->assertJsonPath('error_code', 'freelance_freelancer_team_forbidden');
        $this->deleteJson(self::URL."/teams/{$this->team->uuid}/invitations/{$byLina}")->assertOk();
        $this->as($this->sara);
        $this->deleteJson(self::URL."/teams/{$this->team->uuid}/invitations/{$byOwner}")->assertOk();
        $this->assertSame(2, $this->logged('freelance.team.invitation_cancelled'));
        // Taken back: closed to the person, and a second cancel is refused.
        $this->as($this->omar);
        $this->postJson(self::URL."/team-invitations/{$byOwner}/accept")->assertStatus(409);
        $this->as($this->sara);
        $this->deleteJson(self::URL."/teams/{$this->team->uuid}/invitations/{$byOwner}")->assertStatus(409);
        // An invitation of another team is not reachable through this one.
        $other = app(FreelancerTeams::class)->create($this->huda, 'Huda team');
        $foreign = Invitation::query()->create(['team_id' => $other->id, 'token' => 'x', 'role' => 'member', 'invited_by_freelancer_id' => $this->huda->id, 'status' => 'pending', 'expires_at' => now()->addDay()]);
        $this->deleteJson(self::URL."/teams/{$this->team->uuid}/invitations/{$foreign->uuid}")->assertNotFound();
    }

    public function test_a_person_leaves_at_once_and_the_owners_are_told(): void
    {
        $this->as($this->noor);
        $this->postJson(self::URL."/teams/{$this->team->uuid}/leave", ['reason' => 'Too busy'])->assertOk();
        $row = Member::query()->where('freelancer_id', $this->noor->id)->firstOrFail();
        $this->assertSame([Member::LEFT, 'Too busy'], [$row->status, $row->left_reason]);
        $this->assertSame(1, $this->told($this->sara, 'freelance.team_member_left'));
        $this->assertSame(1, $this->logged('freelance.team.member_left'));
        // He is out: the team looks like it does not exist.
        $this->postJson(self::URL."/teams/{$this->team->uuid}/leave")->assertNotFound();

        // The last Owner can not leave.
        $this->as($this->sara);
        $this->postJson(self::URL."/teams/{$this->team->uuid}/leave")->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_last_owner');

        // He comes back by an invitation, on the same row.
        $uuid = $this->invite($this->sara, '+966500000093')->assertCreated()->json('data.invitation.uuid');
        $this->as($this->noor);
        $this->postJson(self::URL."/team-invitations/{$uuid}/accept")->assertOk();
        $this->assertSame(Member::ACTIVE, $row->refresh()->status);
        $this->assertSame(1, Member::query()->where('freelancer_id', $this->noor->id)->count());
    }

    public function test_the_owner_removes_a_member_and_nobody_else_can(): void
    {
        $this->as($this->lina);
        $this->deleteJson(self::URL."/teams/{$this->team->uuid}/members/{$this->noor->uuid}")->assertForbidden()->assertJsonPath('error_code', 'freelance_freelancer_team_forbidden');
        $this->as($this->sara);
        $this->deleteJson(self::URL."/teams/{$this->team->uuid}/members/{$this->sara->uuid}")->assertForbidden();
        $this->deleteJson(self::URL."/teams/{$this->team->uuid}/members/{$this->huda->uuid}")->assertNotFound();
        $this->deleteJson(self::URL."/teams/{$this->team->uuid}/members/{$this->noor->uuid}", ['reason' => 'Not a fit'])->assertOk();

        $row = Member::query()->where('freelancer_id', $this->noor->id)->firstOrFail();
        $this->assertSame([Member::REMOVED, 'Not a fit', $this->sara->id], [$row->status, $row->left_reason, $row->removed_by_freelancer_id]);
        $this->assertSame(1, $this->told($this->noor, 'freelance.team_member_removed'));
        $this->assertSame(1, $this->logged('freelance.team.member_removed'));
    }

    public function test_it_is_off_until_the_admin_opens_teams_for_the_country(): void
    {
        $uuid = $this->invite($this->sara, '+201000000001')->assertCreated()->json('data.invitation.uuid');
        FreelanceFeatureRule::query()->where('feature', 'teams')->delete();
        FreelanceFeatures::forget();

        $this->as($this->sara);
        $this->postJson(self::URL."/teams/{$this->team->uuid}/invitations", ['role' => 'member'])->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
        $this->as($this->omar);
        $this->getJson(self::URL.'/team-invitations')->assertForbidden();
        $this->postJson(self::URL."/team-invitations/{$uuid}/accept")->assertForbidden();
        $this->assertFalse(app(FreelancerTeams::class)->isMember($this->team, $this->omar));
    }
}
