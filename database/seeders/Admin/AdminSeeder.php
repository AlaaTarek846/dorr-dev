<?php

namespace Database\Seeders\Admin;

use App\Enums\Gender;
use App\Models\Country;
use Illuminate\Database\Seeder;
use Modules\Admin\Models\Admin;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Admin::updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'phone' => '0500000000',
                'phone_code' => '+20',
                'status' => true,
                'gender' => Gender::Male,
                'country_id' => Country::where('code', 'EG')->value('id'),
            ],
        );
    }
}
