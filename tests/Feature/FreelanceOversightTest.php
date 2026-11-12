<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** FL-801 / FL-802: staff's lists of the work with counts, what is late, and the daily alert (once, nothing changed). */
class FreelanceOversightTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private User $client;

    private FreelanceSpecialty $web;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    private function project(string $title, FreelanceProjectStatus $status, array $extra = []): FreelanceProject
    {
        return FreelanceProject::query()->create($extra + [
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => $title,
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => $status, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
    }

    private function staff(string $permission = 'freelance-projects.view'): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        Permission::findOrCreate($permission, 'admin_api');
        $admin->givePermissionTo($permission);

        return $admin;
    }

    private function as(Admin $admin): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    public function test_projects_are_listed_with_filters_and_counts(): void
    {
        $this->project('A booking website', FreelanceProjectStatus::Published);
        $this->project('A shop app', FreelanceProjectStatus::Published);
        $this->project('Logo', FreelanceProjectStatus::PendingReview, ['submitted_at' => now()]);
        $this->as($this->staff());

        $all = $this->getJson('/api/admin/v1/freelance-oversight/projects')->assertOk();
        $this->assertCount(3, $all->json('data'));
        $this->assertSame(2, $all->json('pagination.counts.published'));
        $this->assertSame(1, $all->json('pagination.counts.pending_review'));

        $one = $this->getJson('/api/admin/v1/freelance-oversight/projects?status=pending_review')->assertOk();
        $this->assertSame(['Logo'], array_column($one->json('data'), 'title'));
        $this->assertSame(['A shop app'], array_column($this->getJson('/api/admin/v1/freelance-oversight/projects?q=shop')->json('data'), 'title'));
        $this->getJson('/api/admin/v1/freelance-oversight/offers')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/admin/v1/freelance-oversight/agreements')->assertOk()->assertJsonPath('data', []);
    }

    public function test_only_those_with_the_permission_see_it(): void
    {
        $this->as($this->staff('freelance-reports.view'));
        $this->getJson('/api/admin/v1/freelance-oversight/projects')->assertForbidden();
        $this->getJson('/api/admin/v1/freelance-oversight/late')->assertForbidden();
    }

    public function test_a_project_waiting_too_long_for_review_is_late_and_staff_are_told_once_a_day(): void
    {
        $this->project('Old one', FreelanceProjectStatus::PendingReview, ['submitted_at' => now()->subDays(5)]);
        $this->project('New one', FreelanceProjectStatus::PendingReview, ['submitted_at' => now()]);
        $staff = $this->staff();
        $nobody = Admin::create(['name' => 'X', 'email' => uniqid('x').'@example.com', 'password' => 'secret123', 'status' => 'active']);

        $this->as($staff);
        $late = $this->getJson('/api/admin/v1/freelance-oversight/late')->assertOk();
        $this->assertSame(1, $late->json('data.total'));
        $this->assertSame(['Old one'], array_column($late->json('data.groups.projects_waiting_review'), 'title'));

        $this->artisan('freelance:late-alerts')->assertSuccessful();
        $this->artisan('freelance:late-alerts')->assertSuccessful();
        $this->assertSame(1, $staff->notifications()->where('data->event', 'freelance.late.work')->count());
        $this->assertSame(0, $nobody->notifications()->count());
        // Nothing was changed.
        $this->assertSame(2, FreelanceProject::query()->where('status', FreelanceProjectStatus::PendingReview)->count());
    }

    public function test_nothing_late_means_nobody_is_told(): void
    {
        $this->project('New one', FreelanceProjectStatus::PendingReview, ['submitted_at' => now()]);
        $staff = $this->staff();

        $this->artisan('freelance:late-alerts')->assertSuccessful();
        $this->assertSame(0, $staff->notifications()->count());
    }
}
