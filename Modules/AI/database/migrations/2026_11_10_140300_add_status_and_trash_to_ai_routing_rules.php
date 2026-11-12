<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI routing rules get the countries list (filters, toggle, counts, trash) - without a translation table: a rule has
 * no text people read (a policy, an intent, a provider, a model id, a priority and JSON settings).
 * - is_active is renamed status;
 * - soft deletes. A trashed rule is never used for routing (AiRoutingEngine), like a switched-off one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_routing_rules', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_routing_rules', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_routing_rules', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('ai_routing_rules', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });
    }
};
