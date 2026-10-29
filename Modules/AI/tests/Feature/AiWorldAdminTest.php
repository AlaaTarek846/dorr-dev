<?php

namespace Modules\AI\Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\World\AiWorldCostLedger;
use Modules\AI\Models\World\AiWorldCountryMode;
use Modules\AI\Models\World\AiWorldPrice;
use Modules\AI\Models\World\AiWorldProject;
use Modules\AI\Models\World\AiWorldReport;
use Modules\AI\Models\World\AiWorldSetting;
use Tests\TestCase;

/** DORR AI WORLD ops console API. */
class AiWorldAdminTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $this->country = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'eg'])->id, 'currency_id' => $currency->id,
        ]);
    }

    private function admin(): Admin
    {
        $admin = Admin::query()->create(['name' => 'A', 'email' => 'a-'.uniqid().'@example.test', 'password' => 'password', 'status' => true]);
        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    public function test_requires_an_admin(): void
    {
        $this->getJson('/api/admin/v1/ai-world/settings')->assertStatus(401);
    }

    public function test_settings_are_readable_and_validated(): void
    {
        $this->admin();
        $this->getJson('/api/admin/v1/ai-world/settings')->assertOk()->assertJsonPath('data.max_scenes_per_project', 5);

        $this->putJson('/api/admin/v1/ai-world/settings', ['max_scenes_per_project' => 8, 'retention_days' => 14, 'is_enabled' => false])->assertOk();
        $this->assertSame(8, AiWorldSetting::current()->max_scenes_per_project);
        $this->assertFalse(AiWorldSetting::current()->is_enabled);

        $this->putJson('/api/admin/v1/ai-world/settings', ['retention_days' => 0])->assertStatus(422);
    }

    public function test_country_modes_default_then_override(): void
    {
        $this->admin();
        $this->getJson('/api/admin/v1/ai-world/country-modes')->assertOk()->assertJsonPath('data.0.modes.ar_portal', false)->assertJsonPath('data.0.modes.single', true);

        $this->putJson('/api/admin/v1/ai-world/country-modes', ['countries' => [['country_id' => $this->country->id, 'modes' => ['multi' => false]]]])->assertOk();
        $this->assertFalse(AiWorldCountryMode::isEnabled($this->country->id, 'multi'));

        $this->putJson('/api/admin/v1/ai-world/country-modes', ['countries' => [['country_id' => $this->country->id, 'modes' => ['nope' => true]]]])->assertStatus(422);
    }

    public function test_prices_upsert(): void
    {
        $this->admin();
        $row = ['country_id' => $this->country->id, 'quality' => 'standard', 'price_per_second_minor' => 900];
        $this->putJson('/api/admin/v1/ai-world/prices', ['prices' => [$row]])->assertOk();
        $this->putJson('/api/admin/v1/ai-world/prices', ['prices' => [['price_per_second_minor' => 1100] + $row]])->assertOk();

        $this->assertSame(1, AiWorldPrice::count());
        $this->assertSame(1100, AiWorldPrice::first()->price_per_second_minor);
    }

    public function test_projects_show_internal_ledger_and_reports_can_be_resolved(): void
    {
        $admin = $this->admin();
        $project = AiWorldProject::create(['owner_type' => 'user', 'owner_id' => 7, 'status' => 'completed', 'currency' => 'EGP', 'charged_minor' => 800]);
        AiWorldCostLedger::create(['project_id' => $project->id, 'type' => 'charge', 'amount_minor' => 800, 'currency' => 'EGP']);

        $this->getJson('/api/admin/v1/ai-world/projects?status=completed')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/v1/ai-world/projects/'.$project->id)->assertOk()->assertJsonCount(1, 'data.ledger');
        $this->getJson('/api/admin/v1/ai-world/projects/9999')->assertStatus(404);

        $report = AiWorldReport::create(['project_id' => $project->id, 'reporter_type' => 'user', 'reporter_id' => 7, 'type' => 'complaint']);
        $this->putJson('/api/admin/v1/ai-world/reports/'.$report->id, ['status' => 'resolved'])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertSame($admin->id, (int) $report->refresh()->resolved_by);

        $this->getJson('/api/admin/v1/ai-world/stats')->assertOk()->assertJsonPath('data.projects_by_status.completed', 1)->assertJsonPath('data.open_reports', 0);
    }
}
