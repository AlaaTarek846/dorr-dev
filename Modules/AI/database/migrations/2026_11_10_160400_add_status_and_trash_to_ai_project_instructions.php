<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI project instructions get the countries list (filters, toggle, counts, trash) - without a translation table: the
 * instruction is a prompt sent to the AI as it is (technical, never translated).
 * - is_active is renamed status;
 * - soft deletes. A trashed instruction is never sent to the AI nor shown in the person's project
 *   (AiUserProjectService), like a switched-off one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_project_instructions', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_project_instructions', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_project_instructions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('ai_project_instructions', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });
    }
};
