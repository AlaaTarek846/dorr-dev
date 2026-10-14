<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The cities page is the catalog's own now: discover-cities.view → cities.view, and so on (roles keep what they had). */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('discover-cities', 'cities');
    }

    public function down(): void
    {
        $this->rename('cities', 'discover-cities');
    }

    private function rename(string $from, string $to): void
    {
        DB::table('permissions')->where('name', 'like', $from.'.%')->orderBy('id')->each(function ($permission) use ($from, $to) {
            DB::table('permissions')->where('id', $permission->id)->update([
                'name' => $to.'.'.substr($permission->name, strlen($from) + 1),
                'group_name' => $to,
            ]);
        });

        // Spatie caches permission names: without this the old names keep answering until the cache expires.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
