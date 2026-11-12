<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The customer may accept more than one vehicle type for a move (a quarter or a half truck…). `vehicle_type_id` stays
 * the first choice (the «best fit» of an offer, older apps); `vehicle_type_ids` holds every accepted type, and a
 * request reaches only the branches that have an approved vehicle of one of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moving_requests', function (Blueprint $table) {
            $table->json('vehicle_type_ids')->nullable()->after('vehicle_type_id')->comment('every vehicle type the customer accepts; the first is vehicle_type_id');
        });

        DB::table('moving_requests')->whereNotNull('vehicle_type_id')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('moving_requests')->where('id', $row->id)->update(['vehicle_type_ids' => json_encode([(int) $row->vehicle_type_id])]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('moving_requests', function (Blueprint $table) {
            $table->dropColumn('vehicle_type_ids');
        });
    }
};
