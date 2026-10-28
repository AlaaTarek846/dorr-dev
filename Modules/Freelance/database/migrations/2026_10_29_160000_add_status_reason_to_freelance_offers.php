<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-303: why a client turned an offer down (optional), for the freelancer to read. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_offers', function (Blueprint $table) {
            $table->string('status_reason', 500)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_offers', function (Blueprint $table) {
            $table->dropColumn('status_reason');
        });
    }
};
