<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Services\Catalog\FreelanceSkills;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-203: the admin reviews the clients' projects — the queue, one project with its files, a fix before the decision, and publish /
 * ask for changes / reject (always with a reason). A rejected project is not final: its client reads the reason, edits it and sends
 * it again. Every move is on record, audited, and the client is told at once.
 */
class FreelanceProjectReviewTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_API = '/api/mobile/v1/freelance/projects';

    private const ADMIN_API = '/api/admin/v1/freelance-projects';

    private Country $saudi;

    private FreelanceSpecialty $web;

    private FreelanceSkill $laravel;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();

        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web development'], ['locale' => 'ar', 'name' => 'تطوير مواقع']]);
        $this->laravel = FreelanceSkill::query()->create(['slug' => 'laravel', 'status' => FreelanceSkill::ACTIVE]);
        $this->laravel->translations()->create(['locale' => 'en', 'name' => 'Laravel', 'normalized' => FreelanceSkills::normalize('Laravel')]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client->refresh(), [], 'user_api');
    }

    private function asAdmin(array $permissions = ['freelance-projects.view', 'freelance-projects.update']): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'A', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    /** A complete project sent for review by the client; returns its uuid. */
    private function pending(array $overrides = []): string
    {
        $this->asClient();
        $uuid = $this->postJson(self::CLIENT_API, $overrides + [
            'title' => 'A booking website for my clinic',
            'description' => 'A small site where patients pick a doctor, a day and a time, and get a reminder.',
            'specialty_id' => $this->web->id, 'skill_ids' => [$this->laravel->id],
            'budget_type' => 'range', 'budget_min_minor' => 300000, 'budget_max_minor' => 500000, 'duration_days' => 30, 'visibility' => 'public',
        ])->assertCreated()->json('data.uuid');
        $this->postJson(self::CLIENT_API."/{$uuid}/submit")->assertOk();

        return $uuid;
    }

    public function test_the_queue_lists_what_was_sent_with_counts_and_hides_drafts(): void
    {
        $this->pending();
        $this->asClient();
        $this->postJson(self::CLIENT_API, ['title' => 'Only a draft'])->assertCreated();

        $this->asAdmin();
        $this->getJson(self::ADMIN_API)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'pending_review')
            ->assertJsonPath('data.0.client_name', 'Mona')->assertJsonPath('pagination.counts.pending_review', 1)->assertJsonPath('pagination.counts.published', 0);
        $this->getJson(self::ADMIN_API.'?search=clinic')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::ADMIN_API.'?search=nothing-like-this')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::ADMIN_API.'?status=published')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::ADMIN_API.'/options')->assertOk()->assertJsonPath('data.specialties.0.name', 'Web development');
    }

    public function test_publishing_opens_the_project_tells_the_client_and_is_on_record(): void
    {
        $uuid = $this->pending();
        $this->asAdmin();
        $this->getJson(self::ADMIN_API."/{$uuid}")->assertOk()->assertJsonPath('data.can_decide', true)->assertJsonPath('data.client.name', 'Mona')->assertJsonPath('data.missing', []);

        $this->postJson(self::ADMIN_API."/{$uuid}/publish")->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.can_decide', false);
        $project = FreelanceProject::query()->where('uuid', $uuid)->first();
        $this->assertNotNull($project->published_at);
        $this->assertSame('admin', $project->events()->latest('id')->first()->actor_type);
        $this->assertSame('freelance.project.published', $this->client->notifications()->first()->data['event']);

        // Decided once: a second decision is refused.
        $this->postJson(self::ADMIN_API."/{$uuid}/reject", ['reason' => 'Changed my mind'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_project_invalid_state');
        $this->asClient();
        $this->getJson(self::CLIENT_API."/{$uuid}")->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.can.cancel', true)->assertJsonPath('data.can.edit', false);
    }

    public function test_a_reason_is_required_and_the_client_sees_it(): void
    {
        $uuid = $this->pending();
        $this->asAdmin();
        $this->postJson(self::ADMIN_API."/{$uuid}/reject", [])->assertUnprocessable();
        $this->postJson(self::ADMIN_API."/{$uuid}/request-changes", ['reason' => '   '])->assertUnprocessable();

        $this->postJson(self::ADMIN_API."/{$uuid}/request-changes", ['reason' => 'Say what the reminder should do'])->assertOk()->assertJsonPath('data.status', 'changes_requested');
        $this->asClient();
        $this->getJson(self::CLIENT_API."/{$uuid}")->assertOk()->assertJsonPath('data.status_reason', 'Say what the reminder should do')->assertJsonPath('data.can.edit', true);
        $notice = $this->client->notifications()->first()->data;
        $this->assertSame('freelance.project.changes_requested', $notice['event']);
        $this->assertSame('Say what the reminder should do', $notice['variables']['reason']);
    }

    public function test_a_rejected_project_can_be_read_edited_and_sent_again(): void
    {
        $uuid = $this->pending();
        $this->asAdmin();
        $this->postJson(self::ADMIN_API."/{$uuid}/reject", ['reason' => 'The budget is far too low for this work'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('freelance.project.rejected', $this->client->notifications()->first()->data['event']);

        // The client reads the reason, fixes the budget and sends it again.
        $this->asClient();
        $this->getJson(self::CLIENT_API."/{$uuid}")->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.status_reason', 'The budget is far too low for this work')
            ->assertJsonPath('data.can.edit', true)->assertJsonPath('data.can.submit', true)->assertJsonPath('data.can.delete', false);
        $this->putJson(self::CLIENT_API."/{$uuid}", ['budget_min_minor' => 900000, 'budget_max_minor' => 1200000])->assertOk();
        $this->postJson(self::CLIENT_API."/{$uuid}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.status_reason', null);

        $project = FreelanceProject::query()->where('uuid', $uuid)->first();
        $this->assertSame(['project.created', 'project.submitted', 'project.rejected', 'project.resubmitted'], $project->events()->orderBy('id')->pluck('type')->all());
        $this->assertSame('The budget is far too low for this work', $project->events()->where('type', 'project.rejected')->first()->payload['reason']);

        $this->asAdmin();
        $this->postJson(self::ADMIN_API."/{$uuid}/publish")->assertOk();
    }

    public function test_the_reviewer_can_fix_a_waiting_project_and_the_fix_is_recorded(): void
    {
        $uuid = $this->pending();
        $this->asAdmin();
        $this->putJson(self::ADMIN_API."/{$uuid}", ['title' => 'A booking website for a small clinic', 'duration_days' => 45])->assertOk()
            ->assertJsonPath('data.title', 'A booking website for a small clinic')->assertJsonPath('data.duration_days', 45);

        $project = FreelanceProject::query()->where('uuid', $uuid)->first();
        $edit = $project->events()->where('type', 'project.edited_by_admin')->first();
        $this->assertSame('admin', $edit->actor_type);
        $this->assertEqualsCanonicalizing(['title', 'duration_days'], $edit->payload['fields']);
        $this->assertSame(1, $project->version, 'a fix is not a status move');

        // Same checks as the client's form; a published project is no longer fixable here.
        $this->putJson(self::ADMIN_API."/{$uuid}", ['visibility' => 'secret'])->assertUnprocessable();
        $this->postJson(self::ADMIN_API."/{$uuid}/publish")->assertOk();
        $this->putJson(self::ADMIN_API."/{$uuid}", ['title' => 'Another title entirely now'])->assertStatus(409);
    }

    public function test_a_project_that_fails_the_budget_limits_cannot_be_published(): void
    {
        $uuid = $this->pending();
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['project.min_budget_minor' => 400000]);

        $this->asAdmin();
        $this->postJson(self::ADMIN_API."/{$uuid}/publish")->assertUnprocessable()->assertJsonPath('error_code', 'freelance_project_budget_below_min');
        $this->putJson(self::ADMIN_API."/{$uuid}", ['budget_min_minor' => 450000])->assertOk();
        $this->postJson(self::ADMIN_API."/{$uuid}/publish")->assertOk();
    }

    public function test_files_open_for_the_reviewer_only_through_the_signed_in_endpoint(): void
    {
        $this->asClient();
        $uuid = $this->pending();
        $this->asClient();
        $media = $this->post(self::CLIENT_API."/{$uuid}/attachments", ['file' => UploadedFile::fake()->create('brief.pdf', 40, 'application/pdf')], ['Accept' => 'application/json']);
        // A project under review can't take new files, so add it while it is a draft.
        $media->assertStatus(409);

        $this->postJson(self::CLIENT_API."/{$uuid}/withdraw")->assertOk();
        $id = $this->post(self::CLIENT_API."/{$uuid}/attachments", ['file' => UploadedFile::fake()->create('brief.pdf', 40, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.attachments.0.id');
        $this->postJson(self::CLIENT_API."/{$uuid}/submit")->assertOk();

        $this->asAdmin();
        $this->getJson(self::ADMIN_API."/{$uuid}")->assertOk()->assertJsonPath('data.attachments.0.path', "freelance-projects/{$uuid}/attachments/{$id}");
        $this->get(self::ADMIN_API."/{$uuid}/attachments/{$id}")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->get(self::ADMIN_API."/{$uuid}/attachments/{$id}", ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_only_an_admin_with_the_right_permission_can_review(): void
    {
        $uuid = $this->pending();

        $this->asClient();
        $this->getJson(self::ADMIN_API)->assertUnauthorized();

        $this->asAdmin(['freelance-projects.view']);
        $this->getJson(self::ADMIN_API)->assertOk();
        $this->postJson(self::ADMIN_API."/{$uuid}/publish")->assertForbidden();
        $this->putJson(self::ADMIN_API."/{$uuid}", ['title' => 'Another title entirely now'])->assertForbidden();

        $this->asAdmin([]);
        $this->getJson(self::ADMIN_API)->assertForbidden();
    }
}
