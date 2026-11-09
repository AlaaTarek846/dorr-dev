<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-206c: a PM of a freelancers team can send the team offer, naming the Owner who signs and is paid (D2). The offer stays the Owner's
 * (`freelancer_id`); this keeps the PM who sent it, so he can follow, change and take it back. No foreign key: history, like acted_by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_offers', function (Blueprint $table) {
            $table->unsignedBigInteger('submitted_by_freelancer_id')->nullable()->after('team_id');
            $table->index('submitted_by_freelancer_id', 'freelance_offers_submitted_by_index');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_offers', function (Blueprint $table) {
            $table->dropIndex('freelance_offers_submitted_by_index');
            $table->dropColumn('submitted_by_freelancer_id');
        });
    }
};
