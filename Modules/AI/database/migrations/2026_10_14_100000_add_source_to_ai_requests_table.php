<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a request came from, so the admin usage screens tell the DORR AI chat apart from the AI
 * tools inside the chat and the other modules (AiTaskRunner): null is the DORR AI chat itself,
 * otherwise "chat.translate", "chat.summary", "discover.ask"…
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->string('source', 64)->nullable()->after('intent_id')->comment('مصدر الطلب: null = شات DORR AI، أو أداة زي chat.translate و chat.summary');
            $table->index('source', 'ai_requests_source_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropIndex('ai_requests_source_index');
            $table->dropColumn('source');
        });
    }
};
