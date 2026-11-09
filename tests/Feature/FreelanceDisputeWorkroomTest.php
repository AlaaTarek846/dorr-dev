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
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Http\Controllers\Admin\FreelanceDisputeWorkroomController as Ctl;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceDispute;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceWorkroomComment;
use Modules\Freelance\Models\FreelanceWorkroomDecision;
use Modules\Freelance\Models\FreelanceWorkroomFile;
use Modules\Freelance\Models\FreelanceWorkroomFileVersion;
use Modules\Freelance\Models\FreelanceWorkroomTask;
use Modules\Freelance\Models\Freelancer;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FS-150f: the staff read the shared Workroom of a disputed project - tasks, files with their versions, notes and decisions - reading only,
 * with the disputes desk's view permission, in any state of the dispute; opening it and taking a file are on the audit log.
 */
class FreelanceDisputeWorkroomTest extends TestCase
{
    use RefreshDatabase;

    private FreelanceProject $project;

    private FreelanceDispute $dispute;

    private FreelanceAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(FreelanceWorkroomFileVersion::DISK);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        $web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $freelancer = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->project = $this->project($client, $saudi, $web, 'A booking website');
        $this->agreement = $this->agreement($this->project, $client, $freelancer);
        $part = $this->part($this->agreement, 'Design');
        $this->dispute = FreelanceDispute::query()->create([
            'project_id' => $this->project->id, 'agreement_id' => $this->agreement->id, 'milestone_id' => $part->id, 'opened_by' => 'client', 'opener_id' => $client->id,
            'reason' => 'quality', 'details' => 'Not what we agreed.', 'milestone_status' => 'delivered', 'status' => FreelanceDispute::OPEN,
        ]);
    }

    private function project(User $client, Country $country, FreelanceSpecialty $specialty, string $title): FreelanceProject
    {
        return FreelanceProject::query()->create([
            'client_id' => $client->id, 'country_id' => $country->id, 'freelance_specialty_id' => $specialty->id, 'title' => $title,
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
    }

    private function agreement(FreelanceProject $project, User $client, Freelancer $freelancer): FreelanceAgreement
    {
        $offer = FreelanceOffer::query()->create(['project_id' => $project->id, 'freelancer_id' => $freelancer->id, 'price_minor' => 350000, 'currency_code' => 'SAR', 'duration_days' => 21, 'details' => 'Done.', 'status' => 'accepted']);

        return FreelanceAgreement::query()->create([
            'offer_id' => $offer->id, 'project_id' => $project->id, 'client_id' => $client->id, 'freelancer_id' => $freelancer->id, 'status' => FreelanceAgreement::ACTIVE,
            'scope' => 'A booking site', 'price_minor' => 350000, 'currency_code' => 'SAR', 'duration_days' => 21, 'policy' => [], 'money' => [],
        ]);
    }

    private function part(FreelanceAgreement $a, string $title): FreelanceMilestone
    {
        return FreelanceMilestone::query()->create(['agreement_id' => $a->id, 'project_id' => $a->project_id, 'title' => $title, 'amount_minor' => 100000, 'position' => 1, 'status' => 'delivered']);
    }

    private function admin(array $permissions): \Modules\Admin\Models\Admin
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = \Modules\Admin\Models\Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            \Spatie\Permission\Models\Permission::findOrCreate($name, 'admin_api');
            $admin->givePermissionTo($name);
        }

        return $admin;
    }

    private function asStaff(array $permissions = ['freelance-disputes.view']): \Modules\Admin\Models\Admin
    {
        $this->app['auth']->forgetGuards();
        $admin = $this->admin($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    private function url(string $suffix = '', ?FreelanceDispute $d = null): string
    {
        return '/api/admin/v1/freelance-disputes/'.($d ?? $this->dispute)->uuid.'/workroom'.$suffix;
    }

    /** A file with one version stored for real, on the given project. */
    private function file(FreelanceProject $project, string $title, string $mime = 'application/pdf', string $name = 'plan.pdf'): FreelanceWorkroomFile
    {
        $agreement = FreelanceAgreement::query()->where('project_id', $project->id)->firstOrFail();
        $file = FreelanceWorkroomFile::query()->create([
            'project_id' => $project->id, 'agreement_id' => $agreement->id, 'title' => $title, 'versions_count' => 1, 'created_by_side' => 'freelancer', 'created_by_id' => $agreement->freelancer_id,
        ]);
        $version = FreelanceWorkroomFileVersion::query()->create([
            'file_id' => $file->id, 'number' => 1, 'note' => 'First cut', 'original_name' => $name, 'mime_type' => $mime, 'size_bytes' => 1024,
            'created_by_side' => 'freelancer', 'created_by_id' => $agreement->freelancer_id, 'created_at' => now(),
        ]);
        $version->addFile(str_starts_with($mime, 'image/') ? UploadedFile::fake()->image($name) : UploadedFile::fake()->create($name, 4, $mime));

        return $file;
    }

    /** Tasks, a file, a note and a decision on the disputed project. */
    private function fill(): FreelanceWorkroomFile
    {
        $a = $this->agreement;
        FreelanceWorkroomTask::query()->create(['project_id' => $a->project_id, 'agreement_id' => $a->id, 'title' => 'Send the logo', 'status' => 'todo', 'scope' => 'shared', 'assignee_side' => 'client', 'created_by_side' => 'freelancer', 'created_by_id' => $a->freelancer_id]);
        FreelanceWorkroomTask::query()->create(['project_id' => $a->project_id, 'agreement_id' => $a->id, 'title' => 'Not shared task', 'status' => 'todo', 'scope' => 'internal', 'assignee_side' => 'freelancer', 'created_by_side' => 'freelancer', 'created_by_id' => $a->freelancer_id]);
        $file = $this->file($this->project, 'Home page design');
        FreelanceWorkroomComment::query()->create(['project_id' => $a->project_id, 'agreement_id' => $a->id, 'file_id' => $file->id, 'version_number' => 1, 'body' => 'Make the header bigger', 'author_side' => 'client', 'author_id' => $a->client_id]);
        FreelanceWorkroomDecision::query()->create(['project_id' => $a->project_id, 'agreement_id' => $a->id, 'title' => 'Use the blue theme', 'body' => 'Agreed on a call', 'status' => 'agreed', 'proposed_by_side' => 'client', 'proposed_by_id' => $a->client_id, 'client_ack_at' => now(), 'freelancer_ack_at' => now(), 'answered_at' => now()]);

        return $file;
    }

    public function test_the_staff_read_the_whole_shared_room_of_the_disputed_project(): void
    {
        $this->fill();
        $this->asStaff();

        $r = $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.dispute', $this->dispute->uuid)
            ->assertJsonCount(1, 'data.tasks')->assertJsonPath('data.tasks.0.title', 'Send the logo')->assertJsonPath('data.tasks.0.assignee_side', 'client')
            ->assertJsonCount(1, 'data.files')->assertJsonPath('data.files.0.title', 'Home page design')->assertJsonCount(1, 'data.files.0.versions')
            ->assertJsonPath('data.files.0.versions.0.name', 'plan.pdf')->assertJsonPath('data.files.0.versions.0.note', 'First cut')
            ->assertJsonCount(1, 'data.comments')->assertJsonPath('data.comments.0.body', 'Make the header bigger')->assertJsonPath('data.comments.0.subject.type', 'file')
            ->assertJsonCount(1, 'data.decisions')->assertJsonPath('data.decisions.0.status', 'agreed')->assertJsonPath('data.decisions.0.client_agreed', true);

        // Plain facts: nobody is "me" and nothing can be done from here.
        $text = $r->getContent();
        $this->assertStringNotContainsString('"mine"', $text);
        $this->assertStringNotContainsString('"can"', $text);
        $this->assertStringNotContainsString('Not shared task', $text);
    }

    public function test_it_needs_the_disputes_view_permission(): void
    {
        $this->fill();
        $this->asStaff(['freelance-internal-chat.view']);
        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url('/files/'.FreelanceWorkroomFile::query()->value('uuid').'/versions/1/download'))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson($this->url())->assertUnauthorized();
    }

    public function test_only_the_disputed_projects_room_is_shown(): void
    {
        $this->fill();
        $other = $this->project(User::query()->first(), Country::query()->first(), FreelanceSpecialty::query()->first(), 'Another project');
        $otherAgreement = $this->agreement($other, User::query()->first(), Freelancer::query()->first());
        FreelanceWorkroomTask::query()->create(['project_id' => $other->id, 'agreement_id' => $otherAgreement->id, 'title' => 'Other project task', 'status' => 'todo', 'scope' => 'shared', 'assignee_side' => 'client', 'created_by_side' => 'client', 'created_by_id' => 1]);
        $foreign = $this->file($other, 'Other design');

        $this->asStaff();
        $text = $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data.tasks')->assertJsonCount(1, 'data.files')->getContent();
        $this->assertStringNotContainsString('Other project task', $text);
        $this->assertStringNotContainsString('Other design', $text);
        // A file of another project cannot be taken through this dispute.
        $this->getJson($this->url("/files/{$foreign->uuid}/versions/1/download"))->assertNotFound();
    }

    public function test_opening_the_room_and_taking_a_file_are_on_the_audit_log(): void
    {
        $file = $this->fill();
        $admin = $this->asStaff();

        $this->getJson($this->url())->assertOk();
        $log = AuditLog::query()->where('action', Ctl::VIEWED)->firstOrFail();
        $this->assertSame($this->dispute->id, (int) $log->subject_id);
        $this->assertSame(1, (int) ($log->after['files'] ?? $log->new_values['files'] ?? 1));

        $this->getJson($this->url("/files/{$file->uuid}/versions/1/download"))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(1, AuditLog::query()->where('action', Ctl::DOWNLOADED)->count());
        $this->assertNotNull($admin);
    }

    public function test_the_staff_get_the_original_file_not_a_preview(): void
    {
        $file = $this->file($this->project, 'Logo', 'image/png', 'logo.png');
        $this->asStaff();

        $r = $this->get($this->url("/files/{$file->uuid}/versions/1/download"))->assertOk();
        $this->assertStringContainsString('inline', (string) $r->headers->get('Content-Disposition'));
        $this->getJson($this->url("/files/{$file->uuid}/versions/9/download"))->assertNotFound();
    }

    public function test_it_is_there_in_every_state_of_the_dispute_and_the_room_cannot_be_written_from_here(): void
    {
        $file = $this->fill();
        $this->dispute->forceFill(['status' => FreelanceDispute::RESOLVED])->save();
        $this->asStaff(['freelance-disputes.view', 'freelance-disputes.update']);

        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data.tasks');
        foreach (['postJson', 'putJson', 'deleteJson'] as $verb) {
            $this->{$verb}($this->url())->assertStatus(405);
            $this->{$verb}($this->url("/files/{$file->uuid}/versions/1/download"))->assertStatus(405);
        }
        $this->assertSame(1, FreelanceWorkroomTask::query()->where('scope', 'shared')->count());
    }

    public function test_the_staff_read_the_projects_timeline_of_shared_steps_only_and_it_is_audited(): void
    {
        $a = $this->agreement;
        $event = fn (string $type, string $audience, string $project = null) => \Modules\Freelance\Models\FreelanceProjectEvent::query()->create([
            'project_id' => $project ?? $a->project_id, 'type' => $type, 'actor_type' => 'client', 'actor_id' => $a->client_id, 'payload' => ['title' => 'Send the logo'], 'audience' => $audience,
        ]);
        $event('workroom.task.created', 'both');
        $event('workroom.decision.agreed', 'both');
        $event('workroom.task.updated', 'team');
        $other = $this->project(User::query()->first(), Country::query()->first(), FreelanceSpecialty::query()->first(), 'Another');
        $event('workroom.task.created', 'both', (string) $other->id);

        $this->asStaff();
        $r = $this->getJson($this->url('/timeline'))->assertOk()->assertJsonPath('data.dispute', $this->dispute->uuid)->assertJsonCount(2, 'data.entries');
        $this->assertSame(['decision', 'task'], collect($r->json('data.entries'))->pluck('kind')->sort()->values()->all());
        $this->assertSame([false, false], collect($r->json('data.entries'))->pluck('mine')->all());
        $this->assertSame(1, AuditLog::query()->where('action', Ctl::TIMELINE)->count());

        $this->asStaff(['freelance-internal-chat.view']);
        $this->getJson($this->url('/timeline'))->assertForbidden();
    }
}
