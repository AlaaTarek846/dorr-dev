<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->foreignId('language_id')->nullable()->after('whatsapp_id')->constrained('languages')->nullOnDelete();
            $table->string('category')->nullable()->after('language_id');
            $table->string('meta_template_id')->nullable()->after('category');
            $table->string('meta_language')->nullable()->after('meta_template_id');
            $table->json('components')->nullable()->after('meta_language');
            $table->text('body')->nullable()->after('components');
            $table->text('last_sync_error')->nullable()->after('last_synced_at');
        });

        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->dropColumn('language');
            $table->dropColumn('variables');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->string('language')->nullable();
            $table->json('variables')->nullable();
        });

        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->dropColumn([
                'language_id',
                'category',
                'meta_template_id',
                'meta_language',
                'components',
                'body',
                'last_sync_error',
            ]);
        });
    }
};
