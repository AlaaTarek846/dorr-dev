<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O-86: the motorcycle is hired by the hour, not priced by the distance, and needs no destination —
 * the flag on the category that is already there (new installs get it from the seeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('ride_categories')->where('slug', 'motorcycle')->get(['id', 'flags']) as $row) {
            $flags = json_decode((string) $row->flags, true) ?: [];
            DB::table('ride_categories')->where('id', $row->id)->update(['flags' => json_encode(['hourly_only' => true, 'open_destination_allowed' => true] + $flags)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('ride_categories')->where('slug', 'motorcycle')->get(['id', 'flags']) as $row) {
            $flags = json_decode((string) $row->flags, true) ?: [];
            unset($flags['hourly_only']);
            DB::table('ride_categories')->where('id', $row->id)->update(['flags' => json_encode($flags)]);
        }
    }
};
