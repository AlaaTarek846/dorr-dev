<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The captains get the same support as the riders (docs/rides_tasks.md D-285..D-293): a ticket and its messages
 * belong to a user or to a driver, the ticket has a category (D-289), and each help article says whom it is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreignId('driver_id')->nullable()->after('user_id')->constrained('drivers')->cascadeOnDelete();
            $table->string('category', 32)->nullable()->after('ride_id')->comment('D-289');
            $table->string('reference', 64)->nullable()->after('category')->comment('e.g. the wallet transaction a report is about');
        });
        Schema::table('support_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreignId('driver_id')->nullable()->after('user_id')->constrained('drivers')->cascadeOnDelete();
        });
        Schema::table('support_help_nodes', function (Blueprint $table) {
            $table->string('audience', 8)->default('all')->after('status')->comment('all / user / driver');
        });
    }

    public function down(): void
    {
        Schema::table('support_help_nodes', fn (Blueprint $table) => $table->dropColumn('audience'));
        Schema::table('support_messages', fn (Blueprint $table) => $table->dropConstrainedForeignId('driver_id'));
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
            $table->dropColumn(['category', 'reference']);
        });
    }
};
