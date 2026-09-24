<?php

namespace Database\Seeders\Admin;

use App\Enums\Gender;
use App\Models\Country;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Modules\Admin\Models\Admin;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Admin::updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'phone' => '0500000000',
                'status' => true,
                'gender' => Gender::Male,
                'country_id' => Country::where('code', 'EG')->value('id'),
            ],
        );

        $this->syncAdminServices($admin);
    }

    private function syncAdminServices(Admin $admin): void
    {
        if (! ServiceCategory::query()->exists()) {
            return;
        }

        $categoryIds = ServiceCategory::query()
            ->where('status', true)
            ->where('is_login_dashboard', true)
            ->orderBy('sort_order')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $admin->services()->whereNotIn('service_category_id', $categoryIds)->delete();

        foreach ($categoryIds as $categoryId) {
            $admin->services()->firstOrCreate(['service_category_id' => $categoryId]);
        }
    }
}
