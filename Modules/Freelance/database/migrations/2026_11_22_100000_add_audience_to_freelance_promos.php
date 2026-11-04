<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whose home a promo banner shows on (the client redesign, batch B): the freelancer app's, the client's freelance hub in the Dorr user app,
 * or both. The banners already there stay on the freelancer app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_promos', function (Blueprint $table) {
            // freelancer | client | both (FreelancePromo::AUDIENCES)
            $table->string('audience', 12)->default('freelancer')->after('target');
            $table->index(['audience', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('freelance_promos', function (Blueprint $table) {
            $table->dropIndex(['audience', 'is_active']);
            $table->dropColumn('audience');
        });
    }
};
