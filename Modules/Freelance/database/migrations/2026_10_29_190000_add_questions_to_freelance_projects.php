<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-307: the same questions for everyone who offers, and each offer's answers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_projects', function (Blueprint $table) {
            $table->json('questions')->nullable()->after('description');
        });
        Schema::table('freelance_offers', function (Blueprint $table) {
            $table->json('answers')->nullable()->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_projects', function (Blueprint $table) {
            $table->dropColumn('questions');
        });
        Schema::table('freelance_offers', function (Blueprint $table) {
            $table->dropColumn('answers');
        });
    }
};
