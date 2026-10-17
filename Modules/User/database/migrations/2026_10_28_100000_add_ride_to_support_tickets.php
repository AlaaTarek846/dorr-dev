<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A support ticket can be about a ride (R-127): just the ride's id, no foreign key — the support module
 * doesn't depend on the rides tables, it only quotes the ride's uuid and number back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('ride_id')->nullable()->index()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('ride_id');
        });
    }
};
