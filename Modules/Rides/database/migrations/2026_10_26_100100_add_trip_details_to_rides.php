<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 3 of docs/rides_tasks.md:
 *  - rides.passengers — how many ride (D-60), within the category's seats;
 *  - rides.pickup_note — a short note for the captain about the pickup (D-61);
 *  - rides.wait_warned_at — "the free waiting ends soon" was sent (D-122), once per ride.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->unsignedTinyInteger('passengers')->nullable()->after('beneficiary')->comment('عدد الركاب');
            $table->string('pickup_note', 255)->nullable()->after('passengers')->comment('ملاحظة الوصول للكابتن');
            $table->timestamp('wait_warned_at')->nullable()->after('arrived_at');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['passengers', 'pickup_note', 'wait_warned_at']);
        });
    }
};
