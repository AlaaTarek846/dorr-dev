<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Dashboard pages that had no permission of their own: the AI pages (`admin.area:{group}` — reading needs `.view`,
 * changing needs `.update`), the providers list and the permissions list; and Watch & Earn as its own service.
 */
class AdminAreaPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $permissions = []): Admin
    {
        $admin = Admin::create(['name' => 'A', 'email' => 'a'.uniqid().'@example.com', 'password' => 'secret123', 'status' => 'active']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    public function test_the_ai_pages_need_their_area_permission_to_read_and_to_change(): void
    {
        $this->admin();
        $this->getJson('/api/admin/v1/ai-plans')->assertForbidden();
        $this->getJson('/api/admin/v1/ai-safety-rules')->assertForbidden();

        $this->admin(['ai-plans.view']);
        $this->getJson('/api/admin/v1/ai-plans')->assertOk();
        $this->postJson('/api/admin/v1/ai-plans', [])->assertForbidden();
        $this->getJson('/api/admin/v1/ai-safety-rules')->assertForbidden();

        $this->admin(['ai-plans.view', 'ai-plans.update']);
        $this->postJson('/api/admin/v1/ai-plans', [])->assertStatus(422);
    }

    public function test_the_providers_and_permissions_lists_need_permissions(): void
    {
        $this->admin();
        $this->getJson('/api/admin/v1/providers')->assertForbidden();
        $this->getJson('/api/admin/v1/permissions')->assertForbidden();

        $this->admin(['providers.view', 'roles.view']);
        $this->getJson('/api/admin/v1/providers')->assertOk();
        $this->getJson('/api/admin/v1/permissions')->assertOk();
        $this->postJson('/api/admin/v1/permissions', ['name' => 'x.view'])->assertForbidden();
    }

    public function test_watch_and_earn_is_its_own_service_holding_its_permissions(): void
    {
        $service = ServiceCategory::query()->where('module_name', 'watch_earn')->first();
        $this->assertNotNull($service);
        $this->assertSame(['admin'], $service->audiences);
        $this->assertSame('شاهد واكسب', $service->translations()->where('locale', 'ar')->value('name'));

        $linked = Permission::query()->where('group_name', 'like', 'watch-earn-%')->pluck('service_category_id')->unique()->values()->all();
        $this->assertSame([$service->id], $linked);
        $this->assertTrue(Permission::query()->where('name', 'ai-routing.update')->exists());
    }
}
