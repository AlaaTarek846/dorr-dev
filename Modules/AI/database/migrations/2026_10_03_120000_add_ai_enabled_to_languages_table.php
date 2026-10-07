<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root-cause fix (languages consolidation, step 1/3): the AI module used
 * to keep its own separate "ai_languages" table with an "is_active" flag
 * deciding whether a language was usable by the AI - a redundant, driftable
 * copy of the platform's own general "languages" table. This adds the one
 * thing the AI genuinely needed that the general table didn't have (can a
 * language be used for AI conversations, independent of whether it has a
 * website/dashboard translation) directly onto "languages" itself, so the
 * next migration can move every AI language concern onto this single
 * table instead of a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(true)->after('status')
                ->comment('هل اللغة دي متاحة للاستخدام مع مساعد الذكاء الاصطناعي؟');
        });
    }

    public function down(): void
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->dropColumn('ai_enabled');
        });
    }
};
