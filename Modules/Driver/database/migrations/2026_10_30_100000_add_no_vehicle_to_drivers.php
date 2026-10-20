<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Not every captain has a vehicle (docs/rides_tasks.md O-64): one who drives the riders' own cars («find a
 * driver») or teaches signs up without one. The sign-up then doesn't ask for a vehicle nor its documents;
 * passenger rides still need an approved vehicle (dispatch checks it), the other services don't.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->boolean('no_vehicle')->default(false)->after('residency');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('no_vehicle');
        });
    }
};
