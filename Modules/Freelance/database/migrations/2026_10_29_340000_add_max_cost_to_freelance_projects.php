<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-210: the most the client is ready to pay for a project in all, client fee included (minor units); null = no limit. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_projects', function (Blueprint $table) {
            $table->unsignedBigInteger('max_cost_minor')->nullable()->after('budget_max_minor');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_projects', function (Blueprint $table) {
            $table->dropColumn('max_cost_minor');
        });
    }
};
