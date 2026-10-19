<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * O-17 (owner): the motorcycle is one of the instant-ride categories — from A to B, priced by distance
 * like Economy — not a by-the-hour rental. Databases seeded before keep their row; it is moved here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ride_categories')->where('slug', 'motorcycle')->where('pricing_strategy', 'time')
            ->update(['pricing_strategy' => 'distance', 'capacity_max' => 1, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('ride_categories')->where('slug', 'motorcycle')->update(['pricing_strategy' => 'time']);
    }
};
