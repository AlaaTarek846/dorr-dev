<?php

namespace Modules\Admin\Database\Seeders;

use Database\Seeders\Admin\AdminPermissionSeeder;
use Database\Seeders\Admin\AdminSeeder;
use Illuminate\Database\Seeder;

class AdminDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            AdminSeeder::class,
            AdminPermissionSeeder::class,
        ]);
    }
}
