<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI provider data rules get the countries list (filters, toggle, counts, trash) - without a translation table: a rule
 * has no text people read (a provider, two switches and JSON transformation rules).
 * - is_active is renamed status;
 * - soft deletes. A trashed rule is never applied before a call leaves to the provider (AiGateway::dataRuleFor), like
 *   a switched-off one. The unique provider_id index stays: a provider keeps one rule, the trash included (restore it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_provider_data_rules', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_provider_data_rules', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_data_rules', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('ai_provider_data_rules', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });
    }
};
