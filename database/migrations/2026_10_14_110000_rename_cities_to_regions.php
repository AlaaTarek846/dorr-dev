<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A country's first-level divisions are governorates, states, provinces, regions… — not cities. Same tables, renamed
 * (`cities` → `regions`), so nothing already entered is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('cities', 'regions');
        Schema::rename('city_translations', 'region_translations');

        Schema::table('region_translations', fn ($table) => $table->renameColumn('city_id', 'region_id'));
        Schema::table('service_availability', fn ($table) => $table->renameColumn('city_id', 'region_id'));

        // The admins' permissions follow: cities.view → regions.view, and so on.
        DB::table('permissions')->where('name', 'like', 'cities.%')->orderBy('id')->each(function ($permission) {
            DB::table('permissions')->where('id', $permission->id)->update([
                'name' => 'regions.'.substr($permission->name, strlen('cities.')),
                'group_name' => 'regions',
            ]);
        });
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'like', 'regions.%')->orderBy('id')->each(function ($permission) {
            DB::table('permissions')->where('id', $permission->id)->update([
                'name' => 'cities.'.substr($permission->name, strlen('regions.')),
                'group_name' => 'cities',
            ]);
        });

        Schema::table('service_availability', fn ($table) => $table->renameColumn('region_id', 'city_id'));
        Schema::table('region_translations', fn ($table) => $table->renameColumn('region_id', 'city_id'));

        Schema::rename('region_translations', 'city_translations');
        Schema::rename('regions', 'cities');
    }
};
