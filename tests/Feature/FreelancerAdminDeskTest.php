<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Tests\TestCase;

/**
 * FL-109: staff find freelancers by name / phone, country, city, specialty and status (with a count per status), open one freelancer's
 * page (profile, verification, works, offers, projects, wallet, what staff did) and suspend / block / restore with a reason that is kept.
 */
class FreelancerAdminDeskTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private FreelanceSpecialty $web;

    private FreelanceSpecialty $design;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->design = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 2]);
        $this->design->translations()->createMany([['locale' => 'en', 'name' => 'Design'], ['locale' => 'ar', 'name' => 'تصميم']]);
    }

    private function freelancer(string $name, string $phone, string $status, ?string $city, FreelanceSpecialty $specialty): Freelancer
    {
        $f = Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'city' => $city, 'phone_verified_at' => now(), 'status' => $status]);
        $f->specialties()->attach($specialty->id, ['is_primary' => true]);

        return $f;
    }

    private function asStaff(string ...$permissions): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = \Modules\Admin\Models\Admin::create(['name' => 'Rania Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $p) {
            \Spatie\Permission\Models\Permission::findOrCreate($p, 'admin_api');
            $admin->givePermissionTo($p);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    public function test_the_list_filters_by_country_specialty_and_status_and_counts_each_status(): void
    {
        $this->freelancer('Sara Hassan', '+966500000011', 'verified', 'Riyadh', $this->web);
        $this->freelancer('Lina Omar', '+966500000012', 'verified', 'Jeddah', $this->design);
        $this->freelancer('Noor Adel', '+966500000013', 'suspended', 'Riyadh', $this->design);
        $this->asStaff('freelancers.view');

        $this->getJson('/api/admin/v1/freelancers')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('pagination.counts.verified', 2)->assertJsonPath('pagination.counts.suspended', 1);
        $this->getJson('/api/admin/v1/freelancers?country_id='.$this->saudi->id)->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/admin/v1/freelancers?specialty_id='.$this->design->id)->assertOk()->assertJsonCount(2, 'data');
        $r = $this->getJson('/api/admin/v1/freelancers?status=verified&specialty_id='.$this->web->id)->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame('Sara Hassan', $r['name']);
        $this->assertSame(['Web'], $r['specialties']);
        $this->assertSame('SA', $r['country']);
        $this->getJson('/api/admin/v1/freelancers?search=0013')->assertOk()->assertJsonCount(1, 'data');
        // The counts ignore the status filter, so the tabs stay whole.
        $this->getJson('/api/admin/v1/freelancers?status=suspended')->assertOk()->assertJsonPath('pagination.counts.verified', 2);
    }

    public function test_one_freelancers_page_and_the_trail_of_what_staff_did(): void
    {
        $sara = $this->freelancer('Sara Hassan', '+966500000011', 'verified', 'Riyadh', $this->web);
        $this->asStaff('freelancers.view', 'freelancers.update');

        $this->getJson("/api/admin/v1/freelancers/{$sara->id}")->assertOk()
            ->assertJsonPath('data.profile.specialties.0.name', 'Web')->assertJsonPath('data.stats.offers', 0)
            ->assertJsonPath('data.verification', null)->assertJsonPath('data.audit', []);

        // A reason is needed to suspend; it is kept with who did it.
        $this->postJson("/api/admin/v1/freelancers/{$sara->id}/status", ['status' => 'suspended'])->assertStatus(422);
        $this->postJson("/api/admin/v1/freelancers/{$sara->id}/status", ['status' => 'suspended', 'reason' => 'Complaints under review'])->assertOk();
        $this->postJson("/api/admin/v1/freelancers/{$sara->id}/status", ['status' => 'verified'])->assertOk();

        $audit = $this->getJson("/api/admin/v1/freelancers/{$sara->id}")->assertOk()->json('data.audit');
        $this->assertCount(2, $audit);
        $this->assertSame('Rania Staff', $audit[0]['by']);
        $this->assertSame('suspended', $audit[1]['after']['status']);
        $this->assertSame('Complaints under review', $audit[1]['after']['reason']);
    }

    public function test_a_viewer_cannot_change_a_status(): void
    {
        $sara = $this->freelancer('Sara Hassan', '+966500000011', 'verified', 'Riyadh', $this->web);
        $this->asStaff('freelancers.view');
        $this->postJson("/api/admin/v1/freelancers/{$sara->id}/status", ['status' => 'blocked', 'reason' => 'x'])->assertForbidden();
    }
}
