<?php

use Database\Seeders\General\ServiceCategoriesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Databases that were seeded before the freelance service existed get it without a full re-seed.
     * The seeder is idempotent (rows are matched by their sort order).
     */
    public function up(): void
    {
        // A fresh database (tests, a new install) is empty here and gets the row from the seeder itself.
        if (! DB::table('service_categories')->exists()) {
            return;
        }

        if (! DB::table('service_categories')->where('module_name', 'freelance')->exists()) {
            (new ServiceCategoriesSeeder)->run();
        }
    }

    public function down(): void
    {
        // The service stays: other tables may point at it.
    }
};
