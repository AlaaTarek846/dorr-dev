<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-309: when the client chose the offer, and when the project was awarded. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_offers', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable()->after('viewed_at');
        });
        Schema::table('freelance_projects', function (Blueprint $table) {
            $table->timestamp('awarded_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_offers', function (Blueprint $table) {
            $table->dropColumn('accepted_at');
        });
        Schema::table('freelance_projects', function (Blueprint $table) {
            $table->dropColumn('awarded_at');
        });
    }
};
