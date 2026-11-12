<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learned phrases (the smart intent router's dictionary) get the countries list (filters, toggle, counts, trash) -
 * without a translation table: a phrase is the users' own words the router matches, not a label to translate.
 * - is_active is renamed status (the index ai_learned_intents_active_idx follows the column);
 * - soft deletes. A trashed phrase never matches (AiLearnedIntentStore) and is never switched back on by the router.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_learned_intents', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_learned_intents', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_learned_intents', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('ai_learned_intents', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });
    }
};
