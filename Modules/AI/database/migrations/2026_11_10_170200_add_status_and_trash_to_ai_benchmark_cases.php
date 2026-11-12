<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI benchmark cases get the countries list (filters, toggle, counts, trash) - without a translation table: the
 * question, the expected keywords and the notes are test data and admin notes, not labels people read.
 * - is_active is renamed status;
 * - soft deletes. A trashed case is never run (AiBenchmarkRunner), like a switched-off one; its past results keep it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_benchmark_cases', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_benchmark_cases', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_benchmark_cases', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('ai_benchmark_cases', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });
    }
};
