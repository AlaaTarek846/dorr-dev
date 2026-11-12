<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-503: when a part was paid out and how much reached the freelancer. FL-504: when the client was reminded that an unanswered
 * delivery is about to be approved by itself. FL-408: when the agreement was completed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_milestones', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('paid_minor')->nullable()->comment('what reached the freelancer');
            $table->timestamp('review_reminded_at')->nullable();
        });
        Schema::table('freelance_agreements', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('activated_at');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_milestones', function (Blueprint $table) {
            $table->dropColumn(['released_at', 'paid_minor', 'review_reminded_at']);
        });
        Schema::table('freelance_agreements', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
