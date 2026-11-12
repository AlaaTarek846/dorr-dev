<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamInvitation;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Policy\FreelancePolicyData;
use Tests\TestCase;

/**
 * FS-201: the freelancers' team model and its rules - a verified freelancer creates a team and is its Owner; others join as PM or member
 * (and leave at once); a team always keeps an Owner and ownership is passed by promoting somebody; the admin's limits (teams owned, teams
 * joined, members in a team) stop new additions only; a suspended / archived / deleted team takes nobody and changes nothing; nobody's row is
 * ever deleted. There are no endpoints yet (FS-203 / FS-209), so this goes through the service the endpoints will call.
 */
class FreelanceTeamsTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private FreelancerTeams $teams;

    private Freelancer $sara;

    private Freelancer $lina;

    private Freelancer $noor;

    private Freelancer $huda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        FreelancePolicies::forget();
        $this->teams = app(FreelancerTeams::class);
        $this->sara = $this->freelancer('+966500000091', 'Sara');
        $this->lina = $this->freelancer('+966500000092', 'Lina');
        $this->noor = $this->freelancer('+966500000093', 'Noor');
        $this->huda = $this->freelancer('+966500000094', 'Huda');
    }

    private function freelancer(string $phone, string $name, FreelancerStatus $status = FreelancerStatus::Verified): Freelancer
    {
        return Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => $status->value]);
    }

    /** The numbers the admin has set for the country so far (a save replaces the whole scope, so each step adds to this). */
    private array $caps = [];

    /** Sets the admin's numbers for the country, on top of the ones already set; null = no cap. */
    private function limits(array $values): void
    {
        $this->caps = array_merge($this->caps, $values);
        app(FreelancePolicies::class)->save($this->saudi->id, null, $this->caps);
        FreelancePolicies::forget();
    }

    private function refused(callable $step, string $code, int $status = 422): FreelanceException
    {
        try {
            $step();
        } catch (FreelanceException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->apiStatus());

            return $e;
        }
        $this->fail("Expected the step to be refused with {$code}");
    }

    private function row(FreelanceTeam $team, Freelancer $who): Member
    {
        return Member::query()->where('team_id', $team->id)->where('freelancer_id', $who->id)->firstOrFail();
    }

    public function test_a_verified_freelancer_creates_a_team_and_is_its_owner(): void
    {
        $team = $this->teams->create($this->sara, "  <b>Dorr   Studio</b>  \n ");

        $this->assertSame('Dorr Studio', $team->name);
        $this->assertSame(FreelanceTeam::ACTIVE, $team->status);
        $this->assertSame($this->saudi->id, $team->country_id);
        $this->assertSame($this->sara->id, $team->created_by_freelancer_id);
        $this->assertNotEmpty($team->uuid);
        $this->assertTrue($team->isActive());

        $member = $this->row($team, $this->sara);
        $this->assertSame(Member::OWNER, $member->role);
        $this->assertSame(Member::ACTIVE, $member->status);
        $this->assertNotNull($member->joined_at);
        $this->assertNull($member->permissions);
        $this->assertTrue($this->teams->isOwner($team, $this->sara));
        $this->assertTrue($this->teams->isMember($team, $this->sara));
        $this->assertFalse($this->teams->isMember($team, $this->lina));
        $this->assertSame($this->sara->id, $this->teams->membership($team, $this->sara)->freelancer_id);
        $this->assertSame([$team->id], $this->teams->memberships($this->sara)->pluck('team_id')->all());
        $this->assertSame($team->id, $this->teams->memberships($this->sara)->first()->team->id);
        $this->assertSame(1, $team->activeMembers()->count());
        $this->assertSame($this->sara->id, $team->creator->id);
        $this->assertSame($this->saudi->id, $team->country->id);
    }

    public function test_a_team_needs_a_name_and_a_verified_owner_and_a_refused_one_leaves_nothing(): void
    {
        $this->refused(fn () => $this->teams->create($this->sara, 'ab'), 'freelancer_team_name_required');
        $this->refused(fn () => $this->teams->create($this->sara, '<i></i>   '), 'freelancer_team_name_required');
        foreach ([FreelancerStatus::Registered, FreelancerStatus::ProfileReady, FreelancerStatus::VerificationSubmitted, FreelancerStatus::Suspended, FreelancerStatus::Blocked] as $i => $status) {
            $unverified = $this->freelancer('+96650000020'.$i, 'Dana '.$status->value, $status);
            $this->refused(fn () => $this->teams->create($unverified, 'A good team name'), 'verification_required', 403);
        }

        $this->assertSame(0, FreelanceTeam::query()->count());
        $this->assertSame(0, Member::query()->count());

        // A name is cut at the longest it may be.
        $team = $this->teams->create($this->sara, str_repeat('a', 200));
        $this->assertSame(FreelancerTeams::NAME_MAX, mb_strlen($team->name));
    }

    public function test_a_freelancer_owns_one_team_at_first_and_joins_five_and_the_admin_changes_both(): void
    {
        $this->assertSame(['owned' => ['used' => 0, 'max' => 1], 'joined' => ['used' => 0, 'max' => 5]], $this->teams->limits($this->sara));
        $first = $this->teams->create($this->sara, 'First team');
        $this->assertSame(['owned' => ['used' => 1, 'max' => 1], 'joined' => ['used' => 0, 'max' => 5]], $this->teams->limits($this->sara));

        $e = $this->refused(fn () => $this->teams->create($this->sara, 'Second team'), 'freelancer_team_limit_owned');
        $this->assertSame(['max' => 1], $e->apiData());
        $this->assertSame(1, FreelanceTeam::query()->count());

        // A higher cap opens a place; no cap (an empty value in the admin's form) opens all; a lower cap keeps what is there.
        $this->limits(['teams.owned_max' => 2]);
        $this->teams->create($this->sara, 'Second team');
        $this->refused(fn () => $this->teams->create($this->sara, 'Third team'), 'freelancer_team_limit_owned');
        $this->limits(['teams.owned_max' => null]);
        $this->teams->create($this->sara, 'Third team');
        $this->limits(['teams.owned_max' => 1]);
        $this->assertSame(3, $this->teams->memberships($this->sara)->count());
        $this->assertTrue($this->teams->isOwner($first, $this->sara));
        $this->assertSame(['used' => 3, 'max' => 1], $this->teams->limits($this->sara)['owned']);
        $this->refused(fn () => $this->teams->create($this->sara, 'Fourth team'), 'freelancer_team_limit_owned');
    }

    public function test_a_person_joins_as_a_pm_or_a_member_and_the_owner_role_is_not_given_by_joining(): void
    {
        $team = $this->teams->create($this->sara, 'Dorr Studio');

        $pm = $this->teams->join($team, $this->lina, Member::PM);
        $this->assertSame(Member::PM, $pm->role);
        $this->assertSame(Member::ACTIVE, $pm->status);
        $this->assertNotNull($pm->joined_at);
        $member = $this->teams->join($team, $this->noor);
        $this->assertSame(Member::MEMBER, $member->role);
        $this->assertSame(3, $team->activeMembers()->count());
        $this->assertFalse($this->teams->isOwner($team, $this->lina));

        $this->refused(fn () => $this->teams->join($team, $this->lina), 'freelancer_team_already_member', 409);
        $this->refused(fn () => $this->teams->join($team, $this->huda, Member::OWNER), 'freelancer_team_role_invalid');
        $this->refused(fn () => $this->teams->join($team, $this->huda, 'boss'), 'freelancer_team_role_invalid');
        $unverified = $this->freelancer('+966500000099', 'Dana', FreelancerStatus::ProfileReady);
        $this->refused(fn () => $this->teams->join($team, $unverified), 'verification_required', 403);
        $this->assertSame(3, Member::query()->count());
    }

    public function test_the_joined_limit_counts_the_places_as_a_pm_or_member_only(): void
    {
        $this->limits(['teams.joined_max' => 2, 'teams.owned_max' => null]);
        $a = $this->teams->create($this->lina, 'Team A');
        $b = $this->teams->create($this->noor, 'Team B');
        $c = $this->teams->create($this->huda, 'Team C');

        // Owning her own team does not use a place to join.
        $this->teams->create($this->sara, 'Sara team');
        $this->teams->join($a, $this->sara);
        $this->teams->join($b, $this->sara, Member::PM);
        $this->assertSame(['owned' => ['used' => 1, 'max' => null], 'joined' => ['used' => 2, 'max' => 2]], $this->teams->limits($this->sara));

        $e = $this->refused(fn () => $this->teams->join($c, $this->sara), 'freelancer_team_limit_joined');
        $this->assertSame(['max' => 2], $e->apiData());
        $this->assertFalse($this->teams->isMember($c, $this->sara));

        // Leaving frees a place; a lower cap keeps what is there.
        $this->teams->leave($a, $this->sara);
        $this->teams->join($c, $this->sara);
        $this->limits(['teams.joined_max' => 1]);
        $this->assertTrue($this->teams->isMember($b, $this->sara));
        $this->assertTrue($this->teams->isMember($c, $this->sara));
        $this->refused(fn () => $this->teams->join($a, $this->sara), 'freelancer_team_limit_joined');
        // No cap at all.
        $this->limits(['teams.joined_max' => null]);
        $this->teams->join($a, $this->sara);
        $this->assertSame(3, $this->teams->limits($this->sara)['joined']['used']);
    }

    public function test_a_team_holds_the_admins_most_active_people_and_a_returning_one_counts_too(): void
    {
        $team = $this->teams->create($this->sara, 'Dorr Studio');
        $this->assertNull($this->teams->maxMembers($team));
        $this->limits(['teams.members_max' => 3]);
        $this->assertSame(3, $this->teams->maxMembers($team));

        $this->teams->join($team, $this->lina);
        $this->teams->join($team, $this->noor);
        $e = $this->refused(fn () => $this->teams->join($team, $this->huda), 'freelancer_team_limit_members');
        $this->assertSame(['max' => 3], $e->apiData());

        // A place frees when somebody leaves - and is taken again when he comes back, so the cap holds.
        $this->teams->leave($team, $this->noor);
        $this->teams->join($team, $this->huda);
        $this->refused(fn () => $this->teams->join($team, $this->noor), 'freelancer_team_limit_members');
        // A lower cap keeps everybody; no cap opens the door again.
        $this->limits(['teams.members_max' => 1]);
        $this->assertSame(3, $team->activeMembers()->count());
        $this->limits(['teams.members_max' => null]);
        $this->teams->join($team, $this->noor);
        $this->assertSame(4, $team->activeMembers()->count());
    }

    public function test_leaving_is_immediate_the_row_stays_and_coming_back_starts_fresh_on_the_same_row(): void
    {
        $team = $this->teams->create($this->sara, 'Dorr Studio');
        $row = $this->teams->join($team, $this->lina, Member::PM);
        $row->forceFill(['permissions' => ['view_price' => true]])->save();

        $left = $this->teams->leave($team, $this->lina, ' <i>Moving</i> to another team ');
        $this->assertSame(Member::LEFT, $left->status);
        $this->assertNotNull($left->left_at);
        $this->assertSame('Moving to another team', $left->left_reason);
        $this->assertNull($this->teams->membership($team, $this->lina));
        $this->assertSame([], $this->teams->memberships($this->lina)->all());
        $this->assertSame(1, Member::query()->where('freelancer_id', $this->lina->id)->count());
        $this->refused(fn () => $this->teams->leave($team, $this->lina), 'freelancer_team_not_member', 404);
        $this->refused(fn () => $this->teams->leave($team, $this->noor), 'freelancer_team_not_member', 404);

        // A reason is cut at the longest it may be.
        $other = $this->teams->join($team, $this->noor);
        $this->assertSame(FreelancerTeams::REASON_MAX, mb_strlen($this->teams->leave($team, $this->noor, str_repeat('b', 300))->left_reason));
        $this->assertSame($other->id, $this->row($team, $this->noor)->id);

        $back = $this->teams->join($team, $this->lina);
        $this->assertSame($row->id, $back->id);
        $this->assertSame(Member::MEMBER, $back->role);
        $this->assertSame(Member::ACTIVE, $back->status);
        $this->assertNull($back->permissions);
        $this->assertNull($back->left_at);
        $this->assertNull($back->left_reason);
        $this->assertNull($back->removed_by_freelancer_id);
        $this->assertSame(1, Member::query()->where('freelancer_id', $this->lina->id)->count());
    }

    public function test_the_last_owner_can_not_leave_step_down_or_be_removed_and_ownership_is_passed_by_promoting_somebody(): void
    {
        $team = $this->teams->create($this->sara, 'Dorr Studio');
        $this->teams->join($team, $this->lina, Member::PM);

        $this->refused(fn () => $this->teams->leave($team, $this->sara), 'freelancer_team_last_owner', 409);
        $this->refused(fn () => $this->teams->changeRole($team, $this->sara, $this->sara, Member::MEMBER), 'freelancer_team_last_owner', 409);
        // An Owner never removes himself (he leaves), so the team is never left without one that way either.
        $this->refused(fn () => $this->teams->remove($team, $this->sara, $this->sara), 'freelancer_team_forbidden', 403);
        $this->assertTrue($this->teams->isOwner($team, $this->sara));

        // Pass it on: Lina becomes an Owner, then Sara may step down and leave; Lina is the last one now.
        $this->assertSame(Member::OWNER, $this->teams->changeRole($team, $this->sara, $this->lina, Member::OWNER)->role);
        $this->assertSame(Member::PM, $this->teams->changeRole($team, $this->lina, $this->sara, Member::PM)->role);
        $this->teams->leave($team, $this->sara);
        $this->assertSame(Member::LEFT, $this->row($team, $this->sara)->status);
        $this->assertTrue($this->teams->isOwner($team, $this->lina));
        $this->refused(fn () => $this->teams->leave($team, $this->lina), 'freelancer_team_last_owner', 409);
        $this->refused(fn () => $this->teams->changeRole($team, $this->lina, $this->lina, Member::PM), 'freelancer_team_last_owner', 409);
        $this->assertSame(1, $team->activeMembers()->count());
    }

    public function test_only_an_owner_removes_somebody_else_and_the_row_records_who(): void
    {
        $team = $this->teams->create($this->sara, 'Dorr Studio');
        $this->teams->join($team, $this->lina, Member::PM);
        $this->teams->join($team, $this->noor);

        // A PM, a member and a stranger are not Owners.
        $this->refused(fn () => $this->teams->remove($team, $this->lina, $this->noor), 'freelancer_team_forbidden', 403);
        $this->refused(fn () => $this->teams->remove($team, $this->noor, $this->lina), 'freelancer_team_forbidden', 403);
        $this->refused(fn () => $this->teams->remove($team, $this->huda, $this->noor), 'freelancer_team_forbidden', 403);
        $this->assertTrue($this->teams->isMember($team, $this->noor));

        $removed = $this->teams->remove($team, $this->sara, $this->noor, ' <b>Not a fit</b> ');
        $this->assertSame(Member::REMOVED, $removed->status);
        $this->assertSame($this->sara->id, $removed->removed_by_freelancer_id);
        $this->assertSame('Not a fit', $removed->left_reason);
        $this->assertNotNull($removed->left_at);
        $this->assertNull($this->teams->membership($team, $this->noor));
        $this->refused(fn () => $this->teams->remove($team, $this->sara, $this->noor), 'freelancer_team_not_member', 404);
        $this->refused(fn () => $this->teams->remove($team, $this->sara, $this->huda), 'freelancer_team_not_member', 404);

        // Another Owner may be removed by an Owner (the team still has the one who removed him); the row stays.
        $this->teams->changeRole($team, $this->sara, $this->lina, Member::OWNER);
        $this->teams->remove($team, $this->sara, $this->lina);
        $this->assertSame(Member::REMOVED, $this->row($team, $this->lina)->status);
        $this->assertSame(3, Member::query()->where('team_id', $team->id)->count());
        // The removed one comes back only by joining again, on the same row, with nothing left of the old ones.
        $back = $this->teams->join($team, $this->noor);
        $this->assertSame($removed->id, $back->id);
        $this->assertNull($back->removed_by_freelancer_id);
    }

    public function test_changing_a_role_clears_the_members_permissions_and_checks_the_persons_own_limits(): void
    {
        $team = $this->teams->create($this->sara, 'Dorr Studio');
        $lina = $this->teams->join($team, $this->lina);
        $lina->forceFill(['permissions' => ['view_price' => true]])->save();

        $this->assertSame(Member::MEMBER, $this->teams->changeRole($team, $this->sara, $this->lina, Member::MEMBER)->role);
        $this->assertSame(['view_price' => true], $this->row($team, $this->lina)->permissions);
        $this->assertSame(Member::PM, $this->teams->changeRole($team, $this->sara, $this->lina, Member::PM)->role);
        $this->assertNull($this->row($team, $this->lina)->permissions);
        $this->refused(fn () => $this->teams->changeRole($team, $this->sara, $this->lina, 'boss'), 'freelancer_team_role_invalid');
        $this->refused(fn () => $this->teams->changeRole($team, $this->sara, $this->noor, Member::PM), 'freelancer_team_not_member', 404);
        $this->refused(fn () => $this->teams->changeRole($team, $this->lina, $this->sara, Member::MEMBER), 'freelancer_team_forbidden', 403);

        // Becoming an Owner uses one of the person's places to own: Lina already owns her own team.
        $this->teams->create($this->lina, 'Lina team');
        $this->refused(fn () => $this->teams->changeRole($team, $this->sara, $this->lina, Member::OWNER), 'freelancer_team_limit_owned');
        $this->assertSame(Member::PM, $this->row($team, $this->lina)->role);
        $this->limits(['teams.owned_max' => 2]);
        $this->teams->changeRole($team, $this->sara, $this->lina, Member::OWNER);

        // ...and a new Owner is verified.
        $noor = $this->teams->join($team, $this->noor);
        $this->noor->forceFill(['status' => FreelancerStatus::Suspended->value])->save();
        $this->refused(fn () => $this->teams->changeRole($team, $this->sara, $this->noor->refresh(), Member::OWNER), 'verification_required', 403);
        $this->assertSame(Member::MEMBER, $noor->refresh()->role);

        // Stepping down turns an owned place into a joined one, so it follows the joined limit too.
        $this->limits(['teams.joined_max' => 1, 'teams.owned_max' => 2]);
        $other = $this->teams->create($this->huda, 'Huda team');
        $this->teams->join($other, $this->sara);
        $this->refused(fn () => $this->teams->changeRole($team, $this->lina, $this->sara, Member::PM), 'freelancer_team_limit_joined');
        $this->assertTrue($this->teams->isOwner($team, $this->sara));
        $this->limits(['teams.joined_max' => 2]);
        $this->assertSame(Member::PM, $this->teams->changeRole($team, $this->lina, $this->sara, Member::PM)->role);
    }

    public function test_a_suspended_archived_or_deleted_team_takes_nobody_and_changes_nothing_but_its_people_can_leave(): void
    {
        $team = $this->teams->create($this->sara, 'Dorr Studio');
        $this->teams->join($team, $this->lina, Member::PM);
        $this->teams->join($team, $this->noor);

        foreach ([FreelanceTeam::SUSPENDED, FreelanceTeam::ARCHIVED] as $status) {
            $team->forceFill(['status' => $status])->save();
            $this->refused(fn () => $this->teams->join($team, $this->huda), 'freelancer_team_inactive', 409);
            $this->refused(fn () => $this->teams->remove($team, $this->sara, $this->noor), 'freelancer_team_inactive', 409);
            $this->refused(fn () => $this->teams->changeRole($team, $this->sara, $this->noor, Member::PM), 'freelancer_team_inactive', 409);
            $this->assertSame(Member::MEMBER, $this->row($team, $this->noor)->role);
            $this->assertFalse($team->refresh()->isActive());
        }

        // Its people leave it (the last Owner still keeps the team an Owner).
        $this->teams->leave($team, $this->noor);
        $this->refused(fn () => $this->teams->leave($team, $this->sara), 'freelancer_team_last_owner', 409);
        $team->forceFill(['status' => FreelanceTeam::ACTIVE])->save();

        // A deleted team is gone from the lists and from the person's counts; the rows stay.
        $this->assertSame(1, $this->teams->limits($this->sara)['owned']['used']);
        $team->delete();
        $this->assertSame([], $this->teams->memberships($this->sara)->all());
        $this->assertSame(['used' => 0, 'max' => 1], $this->teams->limits($this->sara)['owned']);
        $this->refused(fn () => $this->teams->join($team, $this->huda), 'freelancer_team_inactive', 409);
        $this->assertSame(3, Member::query()->where('team_id', $team->id)->count());
        $this->teams->create($this->sara, 'A new start');
    }

    public function test_the_teams_a_person_is_in_are_listed_oldest_first_without_the_ones_he_left(): void
    {
        $this->limits(['teams.owned_max' => null]);
        $mine = $this->teams->create($this->sara, 'Sara team');
        $a = $this->teams->create($this->lina, 'Lina team');
        $b = $this->teams->create($this->noor, 'Noor team');
        $this->teams->join($a, $this->sara);
        $this->teams->join($b, $this->sara);
        $this->teams->leave($a, $this->sara);

        $list = $this->teams->memberships($this->sara);
        $this->assertSame([$mine->id, $b->id], $list->pluck('team_id')->all());
        $this->assertSame([Member::OWNER, Member::MEMBER], $list->pluck('role')->all());
        $this->assertSame(['Sara team', 'Noor team'], $list->map(fn (Member $m) => $m->team->name)->all());
        $this->assertSame([], $this->teams->memberships($this->huda)->all());
    }

    public function test_a_team_keeps_its_invitations_ready_for_the_flow_that_will_use_them(): void
    {
        $team = $this->teams->create($this->sara, 'Dorr Studio');

        $direct = FreelanceTeamInvitation::query()->create(['team_id' => $team->id, 'freelancer_id' => $this->lina->id, 'role' => Member::PM, 'invited_by_freelancer_id' => $this->sara->id, 'expires_at' => now()->addDays(7)]);
        $link = FreelanceTeamInvitation::query()->create(['team_id' => $team->id, 'token' => str_repeat('a', 64), 'role' => Member::MEMBER, 'invited_by_freelancer_id' => $this->sara->id]);

        $this->assertSame(FreelanceTeamInvitation::PENDING, $direct->refresh()->status);
        $this->assertNotEmpty($direct->uuid);
        $this->assertNotSame($direct->uuid, $link->uuid);
        $this->assertNull($link->freelancer_id);
        $this->assertSame(2, $team->invitations()->count());
        $this->assertSame($this->lina->id, $direct->freelancer->id);
        $this->assertSame($team->id, $direct->team->id);
        // The token of a link is unique.
        $this->expectException(\Illuminate\Database\QueryException::class);
        FreelanceTeamInvitation::query()->create(['team_id' => $team->id, 'token' => str_repeat('a', 64), 'role' => Member::MEMBER]);
    }

    public function test_one_row_per_person_and_team(): void
    {
        $team = $this->teams->create($this->sara, 'Dorr Studio');

        $this->expectException(\Illuminate\Database\QueryException::class);
        Member::query()->create(['team_id' => $team->id, 'freelancer_id' => $this->sara->id, 'role' => Member::MEMBER, 'status' => Member::ACTIVE]);
    }

    public function test_the_switch_the_limits_and_their_texts_are_registered(): void
    {
        // The switch: off until the admin opens it for the country, like every freelance feature.
        $this->assertContains('teams', FreelanceFeatures::FEATURES);
        $features = app(FreelanceFeatures::class);
        FreelanceFeatures::forget();
        $this->assertFalse($features->snapshot($this->saudi->id)['features']['teams']);
        foreach (['freelance', 'teams'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        $this->assertTrue($features->allows($this->saudi->id, 'teams'));
        $this->assertTrue($features->snapshot($this->saudi->id)['features']['teams']);

        // The limits: D1's numbers by default, validated like every other cap.
        $defaults = FreelancePolicyData::defaults();
        $this->assertSame(1, $defaults['teams.owned_max']);
        $this->assertSame(5, $defaults['teams.joined_max']);
        $this->assertNull($defaults['teams.members_max']);
        $this->assertNull($defaults['teams.offers_monthly'], 'FS-205: the team\'s offers in a month have no cap at first');
        foreach (['teams.owned_max' => 100, 'teams.joined_max' => 100, 'teams.members_max' => 1000, 'teams.offers_monthly' => 100000] as $key => $top) {
            $this->limits([$key => $top]);
            $this->limits([$key => null]);
            foreach ([0, $top + 1] as $bad) {
                $this->refused(fn () => app(FreelancePolicies::class)->save($this->saudi->id, null, [$key => $bad]), 'policy_invalid');
            }
        }

        // Their texts: the admin page (both languages), the Word guide, the switches guide.
        foreach (['ar', 'en'] as $locale) {
            $json = json_decode((string) file_get_contents(resource_path("js/locales/{$locale}.json")), true);
            $text = json_encode($json, JSON_UNESCAPED_UNICODE);
            foreach (['"teams__owned_max"', '"teams__joined_max"', '"teams__members_max"', '"teams__offers_monthly"'] as $needle) {
                $this->assertStringContainsString($needle, $text, "{$needle} is missing in {$locale}");
            }
        }
        $meanings = (new \ReflectionClassConstant(\Modules\Freelance\Services\Policy\FreelancePoliciesGuide::class, 'MEANINGS'))->getValue();
        foreach (['teams.owned_max', 'teams.joined_max', 'teams.members_max', 'teams.offers_monthly'] as $key) {
            $this->assertArrayHasKey($key, $meanings);
        }
        foreach (['freelancer_team_name_required', 'freelancer_team_limit_owned', 'freelancer_team_limit_joined', 'freelancer_team_limit_members', 'freelancer_team_last_owner',
            'freelancer_team_forbidden', 'freelancer_team_not_member', 'freelancer_team_already_member', 'freelancer_team_inactive', 'freelancer_team_role_invalid'] as $code) {
            foreach (['ar', 'en'] as $locale) {
                $this->assertTrue(\Illuminate\Support\Facades\Lang::has("freelance.errors.{$code}", $locale, false), "{$code} is missing in {$locale}");
            }
        }
    }
}
