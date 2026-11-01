<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** FL-L07: a booking is a request until the freelancer accepts it (`pending` -> `booked` | `declined`). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_consultations', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable()->after('confirmed_at');
            // Answered by then, or the request is cancelled and given back.
            $table->timestamp('respond_by')->nullable()->after('accepted_at');
            $table->index(['status', 'respond_by']);
        });
        // Bookings made before this change were confirmed on payment.
        DB::table('freelance_consultations')->whereNotNull('id')->whereIn('status', ['booked', 'completed', 'disputed', 'refunded'])->update(['accepted_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('freelance_consultations', function (Blueprint $table) {
            $table->dropIndex(['status', 'respond_by']);
            $table->dropColumn(['accepted_at', 'respond_by']);
        });
    }
};
