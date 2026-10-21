<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * R-235, R-236, D-390: what the captain has and takes — child seats in the car, pets welcome. A request with a
 * pet / child seats goes only to captains who said so (DispatchEngine::candidates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_preferences', function (Blueprint $table) {
            $table->boolean('pet_friendly')->default(false)->comment('بياخد حيوانات أليفة');
            $table->unsignedTinyInteger('child_seats')->default(0)->comment('مقاعد أطفال في العربية');
        });
    }

    public function down(): void
    {
        Schema::table('driver_preferences', function (Blueprint $table) {
            $table->dropColumn(['pet_friendly', 'child_seats']);
        });
    }
};
