<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-L07: the client rates a consultation once it is over. The rating sits with the project ones in `freelance_reviews`
 * (an agreement or a consultation), so the freelancer's numbers and profile count both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_reviews', function (Blueprint $table) {
            $table->foreignId('agreement_id')->nullable()->change();
            $table->foreignId('project_id')->nullable()->change();
            $table->foreignId('consultation_id')->nullable()->after('project_id')->constrained('freelance_consultations')->cascadeOnDelete();
            $table->unique(['consultation_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::table('freelance_reviews', function (Blueprint $table) {
            $table->dropUnique(['consultation_id', 'side']);
            $table->dropConstrainedForeignId('consultation_id');
        });
    }
};
