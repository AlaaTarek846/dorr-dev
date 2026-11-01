<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The freelancers get the same support as the riders and the captains (the way 2026_10_31_140000_open_support_to_drivers
 * did for drivers): a ticket, its messages and the help-menu presses belong to a user, a driver or a freelancer.
 * A help topic says whom it is for with `audience` (all / user / driver / freelancer — a string, nothing to change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('freelancer_id')->nullable()->after('driver_id')->constrained('freelancers')->cascadeOnDelete();
        });
        Schema::table('support_messages', function (Blueprint $table) {
            $table->foreignId('freelancer_id')->nullable()->after('driver_id')->constrained('freelancers')->cascadeOnDelete();
        });
        // 'freelancer' does not fit the 8 characters the captains' migration gave `audience`.
        Schema::table('support_help_nodes', fn (Blueprint $table) => $table->string('audience', 16)->default('all')->change());
        Schema::table('support_help_feedback', function (Blueprint $table) {
            $table->foreignId('freelancer_id')->nullable()->after('user_id')->constrained('freelancers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('support_help_feedback', fn (Blueprint $table) => $table->dropConstrainedForeignId('freelancer_id'));
        Schema::table('support_messages', fn (Blueprint $table) => $table->dropConstrainedForeignId('freelancer_id'));
        Schema::table('support_tickets', fn (Blueprint $table) => $table->dropConstrainedForeignId('freelancer_id'));
    }
};
