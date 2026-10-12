<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Edit packs: extra AI builds/edits a customer buys for ONE site once its own allowance is used up.
     *
     * - ai_site_offers.kind        'site' (a whole new site) or 'edit_pack' (more edits for an existing site)
     * - ai_site_purchases.project_id  set for an edit pack: the site it tops up (null for a site purchase)
     * - ai_site_versions.consumed_purchase_id  which purchase paid for the build/edit, so a failed one
     *   gives the allowance back to the right place and pack-paid edits do not eat the plan's daily limit.
     */
    public function up(): void
    {
        Schema::table('ai_site_offers', function (Blueprint $table) {
            $table->string('kind', 16)->default('site')->after('code')->index()->comment('site or edit_pack');
        });

        Schema::table('ai_site_purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->after('offer_id')->index();
        });

        Schema::table('ai_site_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('consumed_purchase_id')->nullable()->after('counted')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ai_site_versions', function (Blueprint $table) {
            $table->dropColumn('consumed_purchase_id');
        });

        Schema::table('ai_site_purchases', function (Blueprint $table) {
            $table->dropColumn('project_id');
        });

        Schema::table('ai_site_offers', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
