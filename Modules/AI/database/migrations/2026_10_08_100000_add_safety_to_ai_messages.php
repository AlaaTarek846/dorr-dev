<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DORR AI safety (spec 350–362): how an answer was classified and the approved texts added to it
 * (domain, specific, disclaimer, notice) — for the alert card inside the reply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->json('safety')->nullable()->after('is_error');
        });
    }

    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn('safety');
        });
    }
};
