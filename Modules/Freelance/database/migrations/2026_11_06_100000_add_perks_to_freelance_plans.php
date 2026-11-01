<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a plan shows and opens beyond offers and commission (FL-L01, phase 4): a badge by the freelancer's name, a place in the
 * «recommended» order, the performance numbers, and room for more ready-made services. Free keeps all of them off (the policy rules).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_plans', function (Blueprint $table): void {
            $table->boolean('badge')->default(false)->after('trial_days')->comment('shows the plan badge by the freelancer\'s name');
            $table->unsignedTinyInteger('boost')->default(0)->after('badge')->comment('weight in the recommended order; 0 = none');
            $table->unsignedTinyInteger('analytics')->default(0)->after('boost')->comment('0 = none, 1 = basic (30 days), 2 = full');
            $table->unsignedSmallInteger('services_max')->nullable()->after('analytics')->comment('ready-made services at once; null = the policy');
        });

        // Plans that already exist take the defaults of the config (the seeder never touches a plan that is there).
        foreach ((array) config('freelance.plans') as $slug => $def) {
            $perks = array_intersect_key($def, array_flip(['badge', 'boost', 'analytics', 'services_max']));
            if ($perks !== []) {
                DB::table('freelance_plans')->where('slug', $slug)->update($perks);
            }
        }
    }

    public function down(): void
    {
        Schema::table('freelance_plans', function (Blueprint $table): void {
            $table->dropColumn(['badge', 'boost', 'analytics', 'services_max']);
        });
    }
};
