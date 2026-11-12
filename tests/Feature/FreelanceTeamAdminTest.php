<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Tests\TestCase;

/**
 * FS-208: staff find the freelancers' teams, open one, suspend it with a reason (and bring it back), and take a member out - every step on
 * the audit log, the people it concerns told, a team never left without an Owner (D6). Own permission `freelance-teams`.
 */
class FreelanceTeamAdminTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/v1/freelance-teams';

    private Country $saudi;

    private FreelanceTeam $team;

    private Freelancer $sara;

    private Freelancer $lina;

    private Freelancer $noor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'teams'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        $this->sara = $this->freelancer('+966500000091', 'Sara Ali');
        $this->lina = $this->freelancer('+966500000092', 'Lina Hassan');
        $this->noor = $this->freelancer('+966500000093', 'Noor Said');
        $teams = app(FreelancerTeams::class);
        $this->team = $teams->create($this->sara, 'Dorr Studio');
        $teams->join($this->team, $this->lina, Member::PM);
        $teams->join($this->team, $this->noor);
    }

    private function freelancer(string $phone, string $name): Freelancer
    {
        return Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
    }

    /** @param  list<string>  $permissions */
    private function staff(array $permissions = ['freelance-teams.view', 'freelance-teams.update']): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();
        $admin = \Modules\Admin\Models\Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            \Spatie\Permission\Models\Permission::findOrCreate($name, 'admin_api');
            $admin->givePermissionTo($name);
        }
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    public function test_it_needs_its_own_permission(): void
    {
        $this->staff(['freelancers.view']);
        $this->getJson(self::URL)->assertForbidden();
        $this->staff(['freelance-teams.view']);
        $this->getJson(self::URL)->assertOk();
        $this->postJson(self::URL."/{$this->team->uuid}/status", ['status' => 'suspended', 'reason' => 'x'])->assertForbidden();
    }

    public function test_staff_list_and_open_a_team(): void
    {
        $other = app(FreelancerTeams::class)->create($this->freelancer('+966500000094', 'Huda Omar'), 'Other Team');
        $this->team->update(['name' => 'Dorr Studio']);
        FreelanceTeam::query()->whereKey($other->id)->update(['status' => FreelanceTeam::SUSPENDED]);

        $this->staff();
        $this->getJson(self::URL)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('pagination.counts.active', 1)->assertJsonPath('pagination.counts.suspended', 1);
        $this->getJson(self::URL.'?search=dorr')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.members_count', 3)->assertJsonPath('data.0.projects_running', 0);
        $this->getJson(self::URL.'?status=suspended')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Other Team');

        $show = $this->getJson(self::URL."/{$this->team->uuid}")->assertOk()->assertJsonPath('data.name', 'Dorr Studio')->assertJsonPath('data.teams_enabled', true)
            ->assertJsonCount(3, 'data.members')->assertJsonCount(3, 'data.workload')->assertJsonPath('data.members.0.role', 'owner');
        $this->assertSame('Sara Ali', $show->json('data.created_by'));
        $this->getJson(self::URL.'/'.\Illuminate\Support\Str::uuid())->assertNotFound();
    }

    public function test_suspending_needs_a_reason_is_logged_and_the_owners_are_told(): void
    {
        $this->staff();
        $this->postJson(self::URL."/{$this->team->uuid}/status", ['status' => 'suspended'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_suspend_reason_required');
        $this->postJson(self::URL."/{$this->team->uuid}/status", ['status' => 'suspended', 'reason' => 'Reported for abuse'])->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.status_reason', 'Reported for abuse');
        $this->postJson(self::URL."/{$this->team->uuid}/status", ['status' => 'suspended', 'reason' => 'again'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_team_status_unchanged');
        $this->assertSame(FreelanceTeam::SUSPENDED, $this->team->refresh()->status);

        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.team_suspended')->count());
        $this->assertSame(0, $this->lina->notifications()->where('data->event', 'freelance.team_suspended')->count()); // the Owners only
        $log = AuditLog::query()->where('action', 'freelance.team.status_changed')->firstOrFail();
        $this->assertSame('suspended', $log->after['status']);
        $this->getJson(self::URL."/{$this->team->uuid}")->assertOk()->assertJsonPath('data.audit.0.action', 'freelance.team.status_changed');

        // A suspended team takes nobody and sends no offer (FS-201 / FS-205); leaving stays possible at once (D5).
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->noor->refresh(), [], 'freelance_api');
        $this->postJson('/api/freelance/v1/teams/'.$this->team->uuid.'/leave')->assertOk();

        $this->staff();
        $this->postJson(self::URL."/{$this->team->uuid}/status", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.status_reason', null);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.team_reactivated')->count());
    }

    public function test_staff_take_a_member_out_but_never_the_last_owner(): void
    {
        $this->staff();
        $this->postJson(self::URL."/{$this->team->uuid}/members/{$this->sara->uuid}/remove", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_last_owner');

        $this->postJson(self::URL."/{$this->team->uuid}/members/{$this->noor->uuid}/remove", ['reason' => 'Breaks the rules'])->assertOk();
        $row = Member::query()->where('team_id', $this->team->id)->where('freelancer_id', $this->noor->id)->firstOrFail();
        $this->assertSame(Member::REMOVED, $row->status);
        $this->assertSame('Breaks the rules', $row->left_reason);
        $this->assertNull($row->removed_by_freelancer_id);
        $this->assertSame(1, $this->noor->notifications()->where('data->event', 'freelance.team_member_removed_by_staff')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'freelance.team.member_removed_by_staff')->count());
        $this->getJson(self::URL."/{$this->team->uuid}")->assertOk()->assertJsonPath('data.members.2.status', 'removed')->assertJsonPath('data.members.2.removed_by_staff', true);

        $this->postJson(self::URL."/{$this->team->uuid}/members/{$this->noor->uuid}/remove")->assertNotFound(); // not in the team any more
    }

    public function test_the_texts_are_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $lang) {
            $errors = trans('freelance.errors', [], $lang);
            foreach (['team_archived', 'team_status_unchanged', 'team_suspend_reason_required'] as $key) {
                $this->assertArrayHasKey($key, $errors);
            }
            $notes = trans('notifications', [], $lang);
            foreach (['freelance_team_suspended_title', 'freelance_team_suspended_body', 'freelance_team_reactivated_title', 'freelance_team_reactivated_body', 'freelance_team_member_removed_staff_title', 'freelance_team_member_removed_staff_body'] as $key) {
                $this->assertArrayHasKey($key, $notes);
            }
        }
    }
}
