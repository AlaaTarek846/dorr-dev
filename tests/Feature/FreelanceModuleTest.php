<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use Database\Seeders\Admin\AdminPermissionSeeder;
use Database\Seeders\General\ServiceCategoriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Providers\FreelanceServiceProvider;
use Tests\TestCase;

/** FL-001: the module is loaded, the service exists in the admin catalog and its permissions are seeded. */
class FreelanceModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_module_is_loaded(): void
    {
        $this->assertTrue(class_exists(FreelanceServiceProvider::class));
        $this->assertSame('freelance', config('freelance.service_module_name'));
    }

    public function test_service_category_is_seeded_for_admin_and_user_only(): void
    {
        $this->seed(ServiceCategoriesSeeder::class);

        $category = ServiceCategory::query()->where('module_name', 'freelance')->firstOrFail();
        $this->assertFalse((bool) $category->requires_provider);
        $this->assertContains('admin', $category->audiences);
        $this->assertContains('user', $category->audiences);
    }

    public function test_permissions_are_seeded_under_the_freelance_module(): void
    {
        $this->seed(ServiceCategoriesSeeder::class);
        $this->seed(AdminPermissionSeeder::class);

        foreach (['freelance-settings.view', 'freelancers.update', 'freelance-projects.view', 'freelance-catalogs.delete', 'freelance-disputes.update'] as $name) {
            $this->assertTrue(
                DB::table('permissions')->where('name', $name)->exists(),
                "Missing permission {$name}"
            );
        }
    }
}
