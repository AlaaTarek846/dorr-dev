<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-102 "hire again": a draft asked for again from a finished project remembers who it is meant for and what the last work looked like.
 * The freelancer is invited by himself when the project is published (private project); the parts are only a reference — price, time and
 * scope are agreed again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_projects', function (Blueprint $table) {
            $table->foreignId('rehire_freelancer_id')->nullable()->after('visibility')->constrained('freelancers')->nullOnDelete();
            $table->foreignId('rehire_from_project_id')->nullable()->after('rehire_freelancer_id')->constrained('freelance_projects')->nullOnDelete();
            $table->json('rehire_plan')->nullable()->after('rehire_from_project_id')->comment('the last work\'s parts: title, amount_minor, duration_days (a reference, not an agreement)');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rehire_freelancer_id');
            $table->dropConstrainedForeignId('rehire_from_project_id');
            $table->dropColumn('rehire_plan');
        });
    }
};
