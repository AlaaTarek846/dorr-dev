<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Tests\TestCase;

/**
 * FS-202b: a team's verification (D4, optional). Only an Owner of an active team uploads papers and sends them; a request under review locks the
 * papers; an admin (with `freelance-teams`) opens a paper (audited), approves or rejects with a reason, or takes the badge back; the Owners are
 * told and the badge shows on the profile and in the list of teams. The papers are private: nobody but the Owners and an admin opens them.
 */
class FreelanceTeamVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const ADMIN = '/api/admin/v1/freelance-teams';

    private Country $saudi;

    private FreelanceTeam $team;

    private Freelancer $sara;

    private Freelancer $lina;

    private Freelancer $noor;

    private Freelancer $omar;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'teams'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        $this->sara = $this->freelancer('+966500000091', 'Sara Ali');
        $this->lina = $this->freelancer('+966500000092', 'Lina Hassan');
        $this->noor = $this->freelancer('+966500000093', 'Noor Said');
        $this->omar = $this->freelancer('+966500000095', 'Omar Khaled'); // not in the team
        $teams = app(FreelancerTeams::class);
        $this->team = $teams->create($this->sara, 'Dorr Studio');
        $teams->join($this->team, $this->lina, Member::PM);
        $teams->join($this->team, $this->noor);
        $teams->changeRole($this->team, $this->sara, $this->noor, Member::OWNER); // a second Owner
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

    private function url(string $suffix = ''): string
    {
        return self::API."/teams/{$this->team->uuid}/verification".$suffix;
    }

    private function pdf(string $name = 'register.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF");
    }

    private function upload(Freelancer $who, string $title = 'Commercial register'): \Illuminate\Testing\TestResponse
    {
        $this->as($who);

        return $this->postJson($this->url('/documents'), ['title' => $title, 'file' => $this->pdf()]);
    }

    private function send(): void
    {
        $this->upload($this->sara)->assertCreated();
        $this->postJson($this->url('/submit'))->assertOk()->assertJsonPath('data.status', 'submitted');
    }

    public function test_an_owner_uploads_papers_and_sends_them_and_nobody_else_can(): void
    {
        $this->as($this->sara);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.status', 'none')->assertJsonPath('data.can_submit', false)->assertJsonCount(0, 'data.documents');
        $this->postJson($this->url('/submit'))->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_verification_empty');
        $this->postJson($this->url('/documents'), ['title' => '   ', 'file' => $this->pdf()])->assertStatus(422);
        $this->postJson($this->url('/documents'), ['title' => 'Register', 'file' => UploadedFile::fake()->create('x.exe', 10)])->assertStatus(422);

        $doc = $this->upload($this->sara, ' <b>Commercial</b> register ')->assertCreated()->assertJsonPath('data.title', 'Commercial register')->json('data');
        $this->assertStringStartsWith("teams/{$this->team->uuid}/verification/documents/", $doc['file']['path']);
        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data.documents')->assertJsonPath('data.can_submit', true);

        // A paper not sent yet can be taken back; a PM, a member or an outsider does not even find the route.
        $this->upload($this->sara, 'Partnership')->assertCreated();
        $gone = $this->getJson($this->url())->json('data.documents.1.uuid');
        $this->deleteJson($this->url("/documents/{$gone}"))->assertOk();
        foreach ([$this->lina, $this->omar] as $who) {
            $this->as($who);
            $this->getJson($this->url())->assertNotFound();
            $this->postJson($this->url('/documents'), ['title' => 'x', 'file' => $this->pdf()])->assertNotFound();
            $this->getJson($this->url("/documents/{$doc['uuid']}"))->assertNotFound();
        }

        // The other Owner opens it and sends the request.
        $this->as($this->noor);
        $this->getJson($this->url("/documents/{$doc['uuid']}"))->assertOk();
        $this->postJson($this->url('/submit'))->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.last_request.documents.0.title', 'Commercial register');
        $this->assertDatabaseHas('audit_logs', ['action' => 'freelance.team.verification_submitted']);

        // Under review the papers are locked.
        $this->postJson($this->url('/documents'), ['title' => 'More', 'file' => $this->pdf()])->assertStatus(409)->assertJsonPath('error_code', 'freelance_team_verification_locked');
        $this->postJson($this->url('/submit'))->assertStatus(409);
        $this->assertSame('submitted', $this->team->refresh()->verificationRequests()->value('status'));
    }

    public function test_the_admin_opens_a_paper_approves_and_the_team_gets_the_badge(): void
    {
        $this->send();
        $this->staff(['freelance-teams.view']);
        $show = $this->getJson(self::ADMIN."/{$this->team->uuid}")->assertOk()->assertJsonPath('data.verification.requests.0.status', 'submitted');
        $path = $show->json('data.verification.requests.0.documents.0.file.path');
        $this->assertStringStartsWith("freelance-teams/{$this->team->uuid}/verification/documents/", $path);
        $this->get('/api/admin/v1/'.$path)->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'freelance.team.verification_file_opened']);
        $this->getJson(self::ADMIN.'?verification=pending')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.verification_pending', true)->assertJsonPath('pagination.verification_pending', 1);
        // Reading is not deciding.
        $this->postJson(self::ADMIN."/{$this->team->uuid}/verification/decide", ['decision' => 'approve'])->assertForbidden();

        $this->staff();
        $this->postJson(self::ADMIN."/{$this->team->uuid}/verification/decide", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.requests.0.status', 'approved');
        $this->assertNotNull($this->team->refresh()->verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'freelance.team.verification_decided']);
        // Both Owners are told; the PM is not.
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.team_verified')->count());
        $this->assertSame(1, $this->noor->notifications()->where('data->event', 'freelance.team_verified')->count());
        $this->assertSame(0, $this->lina->notifications()->where('data->event', 'freelance.team_verified')->count());
        $this->getJson(self::ADMIN.'?verification=verified')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.verified', true);
        $this->getJson(self::ADMIN.'?verification=pending')->assertOk()->assertJsonCount(0, 'data');

        // The badge shows to the team, on its profile, and a verified team has nothing more to send.
        $this->as($this->sara);
        $this->getJson(self::API."/teams/{$this->team->uuid}/profile")->assertOk()->assertJsonPath('data.team.verified', true)->assertJsonPath('data.team.verification_status', 'verified');
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.can_submit', false);
        $this->postJson($this->url('/documents'), ['title' => 'More', 'file' => $this->pdf()])->assertStatus(409)->assertJsonPath('error_code', 'freelance_team_already_verified');

        // A decided request is not decided again.
        $this->staff();
        $this->postJson(self::ADMIN."/{$this->team->uuid}/verification/decide", ['decision' => 'reject', 'reason' => 'x'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_team_verification_decided');
    }

    public function test_a_rejection_needs_a_reason_and_the_owners_can_try_again_with_new_papers(): void
    {
        $this->send();
        $this->staff();
        $url = self::ADMIN."/{$this->team->uuid}/verification/decide";
        $this->postJson($url, ['decision' => 'reject'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_verification_reason_required');
        $this->postJson($url, ['decision' => 'reject', 'reason' => 'The register is expired'])->assertOk()->assertJsonPath('data.requests.0.status', 'rejected');
        $this->assertNull($this->team->refresh()->verified_at);

        $this->as($this->sara);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.last_request.reason', 'The register is expired')
            ->assertJsonPath('data.can_edit', true)->assertJsonCount(0, 'data.documents');
        $this->getJson(self::API."/teams/{$this->team->uuid}/profile")->assertOk()->assertJsonPath('data.team.verification_status', 'rejected');

        // The first try keeps its papers; a second request is made of new ones.
        $this->upload($this->sara, 'New register')->assertCreated();
        $this->postJson($this->url('/submit'))->assertOk()->assertJsonPath('data.last_request.documents.0.title', 'New register');
        $this->staff();
        $this->getJson(self::ADMIN."/{$this->team->uuid}")->assertOk()->assertJsonCount(2, 'data.verification.requests')
            ->assertJsonPath('data.verification.requests.0.status', 'submitted')->assertJsonPath('data.verification.requests.1.status', 'rejected')
            ->assertJsonPath('data.verification.requests.1.documents.0.title', 'Commercial register');
    }

    public function test_the_admin_takes_the_badge_back_with_a_reason_and_it_leaves_every_surface(): void
    {
        $this->send();
        $this->staff();
        $this->postJson(self::ADMIN."/{$this->team->uuid}/verification/decide", ['decision' => 'approve'])->assertOk();
        $url = self::ADMIN."/{$this->team->uuid}/verification/revoke";
        $this->postJson($url, [])->assertStatus(422);
        $this->postJson($url, ['reason' => 'The papers were forged'])->assertOk()->assertJsonPath('data.verified_at', null);
        $this->assertNull($this->team->refresh()->verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'freelance.team.verification_revoked']);
        $this->postJson($url, ['reason' => 'again'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_team_not_verified');
        $this->getJson(self::ADMIN.'?verification=verified')->assertOk()->assertJsonCount(0, 'data');

        $this->as($this->sara);
        $this->getJson(self::API."/teams/{$this->team->uuid}/profile")->assertOk()->assertJsonPath('data.team.verified', false);
        $this->assertSame(1, AuditLog::query()->where('action', 'freelance.team.verification_revoked')->count());
    }

    public function test_a_suspended_team_sends_nothing_and_the_papers_are_private(): void
    {
        Storage::fake('public');
        $this->upload($this->sara)->assertCreated();
        $this->team->update(['status' => FreelanceTeam::SUSPENDED]);
        $this->as($this->sara);
        $this->postJson($this->url('/submit'))->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_inactive');

        // The file sits on the private disk, never on the public one.
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
