<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Source download (the site as a ZIP) is one admin-controlled offer of kind 'source_download':
     * is_active switches the feature on/off, is_free makes it free, otherwise the per-country price
     * is charged once per site (an ai_site_purchases row pointing at the project unlocks it).
     */
    public function up(): void
    {
        Schema::table('ai_site_offers', function (Blueprint $table) {
            $table->boolean('is_free')->default(false)->after('is_active')->comment('source_download only: no charge');
        });
    }

    public function down(): void
    {
        Schema::table('ai_site_offers', function (Blueprint $table) {
            $table->dropColumn('is_free');
        });
    }
};
