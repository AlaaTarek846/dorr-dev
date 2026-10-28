<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Catalog\FreelanceSkills;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Projects\ProjectOffersWindow;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-204: a published project takes offers for a window taken from the policy when it is published; it closes by itself when the time
 * is up (and at the moment someone tries to offer), or by hand; it can be opened again — a few times for the client, freely for an admin.
 */
class FreelanceProjectOffersWindowTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_API = '/api/mobile/v1/freelance/projects';

    private const ADMIN_API = '/api/admin/v1/freelance-projects';

    private Country $saudi;

    private User $client;

    private FreelanceSpecialty $web;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $skill = FreelanceSkill::query()->create(['slug' => 'laravel', 'status' => FreelanceSkill::ACTIVE]);
        $skill->translations()->create(['locale' => 'en', 'name' => 'Laravel', 'normalized' => FreelanceSkills::normalize('Laravel')]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    private function window(): ProjectOffersWindow
    {
        return app(ProjectOffersWindow::class);
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client->refresh(), [], 'user_api');
    }

    private function asAdmin(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'A', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach (['freelance-projects.view', 'freelance-projects.update'] as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo(['freelance-projects.view', 'freelance-projects.update']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    /** A project sent by the client and published by an admin through the real endpoints; returns its uuid. */
    private function published(): string
    {
        $this->asClient();
        $uuid = $this->postJson(self::CLIENT_API, [
            'title' => 'A booking website for my clinic', 'description' => 'A small site where patients pick a doctor, a day and a time, and get a reminder.',
            'specialty_id' => $this->web->id, 'skill_ids' => [FreelanceSkill::query()->value('id')],
            'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'duration_days' => 30, 'visibility' => 'public',
        ])->assertCreated()->json('data.uuid');
        $this->postJson(self::CLIENT_API."/{$uuid}/submit")->assertOk();
        $this->asAdmin();
        $this->postJson(self::ADMIN_API."/{$uuid}/publish")->assertOk();

        return $uuid;
    }

    private function project(string $uuid): FreelanceProject
    {
        return FreelanceProject::query()->where('uuid', $uuid)->firstOrFail();
    }

    public function test_publishing_starts_a_window_of_the_policy_length_and_a_policy_change_later_does_not_touch_it(): void
    {
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['offers.window_days' => 5]);
        $uuid = $this->published();

        $deadline = $this->project($uuid)->offers_deadline_at;
        $this->assertEqualsWithDelta(now()->addDays(5)->timestamp, $deadline->timestamp, 5);

        app(FreelancePolicies::class)->save($this->saudi->id, null, ['offers.window_days' => 30]);
        $this->assertTrue($this->project($uuid)->offers_deadline_at->equalTo($deadline));
        $this->assertTrue($this->window()->accepting($this->project($uuid)));

        $this->asClient();
        $this->getJson(self::CLIENT_API."/{$uuid}")->assertOk()->assertJsonPath('data.offers.accepting', true)->assertJsonPath('data.offers.can_close', true)->assertJsonPath('data.offers.can_reopen', false);
    }

    public function test_when_the_time_is_up_the_command_closes_the_offers_tells_the_client_and_is_safe_to_repeat(): void
    {
        $uuid = $this->published();
        $this->project($uuid)->update(['offers_deadline_at' => now()->subMinute()]);

        // The clock already says no, before any command ran.
        $this->assertFalse($this->window()->accepting($this->project($uuid)->refresh()));
        try {
            $this->window()->assertAccepting($this->project($uuid));
            $this->fail('Offers must be refused after the deadline.');
        } catch (FreelanceException $e) {
            $this->assertSame('project_offers_closed', $e->errorCode);
        }

        Artisan::call('freelance:close-expired-projects');
        $project = $this->project($uuid);
        $this->assertNotNull($project->offers_closed_at);
        $this->assertSame('deadline', $project->offers_close_reason);
        $this->assertSame('project.offers_closed', $project->events()->latest('id')->first()->type);
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.project.offers_closed')->count());

        Artisan::call('freelance:close-expired-projects');
        $this->assertSame(1, $project->events()->where('type', 'project.offers_closed')->count());
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.project.offers_closed')->count());
    }

    public function test_the_client_closes_by_hand_and_opens_again_up_to_the_limit(): void
    {
        $uuid = $this->published();
        $this->asClient();
        $this->postJson(self::CLIENT_API."/{$uuid}/offers/reopen")->assertStatus(409)->assertJsonPath('error_code', 'freelance_project_offers_already_open');

        $this->postJson(self::CLIENT_API."/{$uuid}/offers/close")->assertOk()->assertJsonPath('data.offers.accepting', false)->assertJsonPath('data.offers.close_reason', 'client')->assertJsonPath('data.offers.can_reopen', true);
        $this->assertSame('published', $this->project($uuid)->status->value, 'closing the offers is not cancelling the project');
        $this->postJson(self::CLIENT_API."/{$uuid}/offers/close")->assertStatus(409)->assertJsonPath('error_code', 'freelance_project_offers_not_open');

        $max = (int) config('freelance.projects.max_reopens');
        foreach (range(1, $max) as $i) {
            $this->postJson(self::CLIENT_API."/{$uuid}/offers/reopen")->assertOk()->assertJsonPath('data.offers.accepting', true)->assertJsonPath('data.offers.reopens_left', $max - $i);
            $this->postJson(self::CLIENT_API."/{$uuid}/offers/close")->assertOk();
        }
        $this->postJson(self::CLIENT_API."/{$uuid}/offers/reopen")->assertUnprocessable()->assertJsonPath('error_code', 'freelance_project_reopen_limit');
        $this->getJson(self::CLIENT_API."/{$uuid}")->assertJsonPath('data.offers.can_reopen', false)->assertJsonPath('data.offers.reopens_left', 0);

        // An admin is not held to the limit, and the window starts again from now.
        $this->asAdmin();
        $this->postJson(self::ADMIN_API."/{$uuid}/offers/reopen")->assertOk()->assertJsonPath('data.offers.accepting', true);
        $this->assertTrue($this->window()->accepting($this->project($uuid)));
        $this->assertSame($max + 1, $this->project($uuid)->reopen_count);
    }

    public function test_an_expired_project_can_be_opened_again_by_its_client(): void
    {
        $uuid = $this->published();
        $this->project($uuid)->update(['offers_deadline_at' => now()->subDay()]);

        $this->asClient();
        $this->getJson(self::CLIENT_API."/{$uuid}")->assertJsonPath('data.offers.accepting', false)->assertJsonPath('data.offers.close_reason', 'deadline')->assertJsonPath('data.offers.can_reopen', true);
        $this->postJson(self::CLIENT_API."/{$uuid}/offers/reopen")->assertOk()->assertJsonPath('data.offers.accepting', true);
        $this->assertTrue($this->project($uuid)->offers_deadline_at->isFuture());
    }

    public function test_only_a_published_project_has_a_window(): void
    {
        $this->asClient();
        $draft = $this->postJson(self::CLIENT_API, ['title' => 'Only a draft'])->assertCreated()->json('data.uuid');
        $this->postJson(self::CLIENT_API."/{$draft}/offers/close")->assertStatus(409)->assertJsonPath('error_code', 'freelance_project_invalid_state');
        $this->postJson(self::CLIENT_API."/{$draft}/offers/reopen")->assertStatus(409);
        $this->assertFalse($this->window()->accepting($this->project($draft)));

        // Nobody else can close or open mine.
        $uuid = $this->published();
        $other = User::query()->create(['name' => 'Omar', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, [], 'user_api');
        $this->postJson(self::CLIENT_API."/{$uuid}/offers/close")->assertNotFound();
    }

    public function test_the_freelancer_sees_the_window_and_a_closed_project_stays_listed_but_marked(): void
    {
        $uuid = $this->published();
        $me = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($me, [], 'freelance_api');

        $this->getJson('/api/freelance/v1/projects')->assertOk()->assertJsonPath('data.0.offers.accepting', true)->assertJsonMissingPath('data.0.offers.can_close');
        $this->project($uuid)->update(['offers_deadline_at' => now()->subHour()]);
        $this->getJson('/api/freelance/v1/projects')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.offers.accepting', false)->assertJsonPath('data.0.offers.close_reason', 'deadline');
        $this->getJson("/api/freelance/v1/projects/{$uuid}")->assertOk()->assertJsonPath('data.offers.accepting', false);
    }

    public function test_the_command_is_scheduled_hourly(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'freelance:close-expired-projects'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }
}
