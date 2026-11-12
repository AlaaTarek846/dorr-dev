<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Models\FreelanceChecklistTemplate;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Projects\ChecklistTemplateDefaults;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FS-105: ready-made checklists by kind of project. The admin keeps them in both languages (one check per line, as many as a part takes);
 * a client reads the active ones and starts a part's checklist from one.
 */
class FreelanceChecklistTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/admin/v1/freelance-checklist-templates';

    private const CLIENT = '/api/mobile/v1/freelance/checklist-templates';

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        ChecklistTemplateDefaults::ensure();
    }

    private function asAdmin(array $permissions = ['freelance-checklist-templates.view', 'freelance-checklist-templates.create', 'freelance-checklist-templates.update', 'freelance-checklist-templates.delete']): void
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

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client->refresh(), [], 'user_api');
    }

    public function test_the_first_templates_are_there_and_a_client_reads_the_active_ones_in_his_language(): void
    {
        $this->assertSame(['design', 'marketing', 'mobile_app', 'website', 'writing'], FreelanceChecklistTemplate::query()->orderBy('code')->pluck('code')->all());
        ChecklistTemplateDefaults::ensure();
        $this->assertSame(5, FreelanceChecklistTemplate::query()->count());

        $this->asClient();
        app()->setLocale('en');
        $en = $this->getJson(self::CLIENT)->assertOk()->assertJsonCount(5, 'data')->json('data');
        $this->assertSame('Website', $en[0]['name']);
        $this->assertSame('All agreed pages are built', $en[0]['items'][0]);
        $this->assertLessThanOrEqual(10, count($en[0]['items']));

        FreelanceChecklistTemplate::query()->where('code', 'writing')->update(['status' => false]);
        $this->getJson(self::CLIENT)->assertJsonCount(4, 'data');
    }

    public function test_the_admin_adds_edits_switches_off_and_deletes_one(): void
    {
        $this->asAdmin();
        $body = [
            'code' => 'seo', 'name' => ['ar' => 'تحسين محركات البحث', 'en' => 'SEO'],
            'items' => ['ar' => ['تقرير الكلمات المفتاحية', 'تحسين العناوين'], 'en' => ['Keyword report', 'Title tags fixed']],
        ];
        $id = $this->postJson(self::ADMIN, $body)->assertCreated()->assertJsonPath('data.names.en', 'SEO')->assertJsonPath('data.items.ar.1', 'تحسين العناوين')->json('data.id');

        // The code is unique, and at most as many checks as a part takes.
        $this->postJson(self::ADMIN, $body)->assertUnprocessable();
        $many = ['ar' => array_fill(0, 11, 'x'), 'en' => array_fill(0, 11, 'x')];
        $this->postJson(self::ADMIN, ['code' => 'big', 'items' => $many] + $body)->assertUnprocessable();

        $this->putJson(self::ADMIN."/{$id}", ['items' => ['ar' => ['واحد'], 'en' => ['One']]] + $body)->assertOk()->assertJsonCount(1, 'data.items.en');
        $this->patchJson(self::ADMIN."/{$id}/status", ['status' => false])->assertOk()->assertJsonPath('data.status', false);
        $this->getJson(self::ADMIN)->assertOk()->assertJsonCount(6, 'data');
        $this->getJson(self::ADMIN.'/options')->assertOk()->assertJsonPath('data.max_checks', 10);

        $this->asClient();
        $this->getJson(self::CLIENT)->assertJsonCount(5, 'data');

        $this->asAdmin();
        $this->deleteJson(self::ADMIN."/{$id}")->assertOk();
        $this->assertNull(FreelanceChecklistTemplate::query()->find($id));
    }

    public function test_the_admin_pages_need_their_permission(): void
    {
        $this->asAdmin(['freelance-checklist-templates.view']);
        $this->getJson(self::ADMIN)->assertOk();
        $this->postJson(self::ADMIN, ['code' => 'seo'])->assertForbidden();
        $this->deleteJson(self::ADMIN.'/1')->assertForbidden();
    }
}
