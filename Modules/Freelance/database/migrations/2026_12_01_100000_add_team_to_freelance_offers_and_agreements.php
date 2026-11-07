<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-205: an offer in a freelancers' team's name. The offer and its agreement keep the Owner who signs (`freelancer_id`, D2) and add the team
 * (`team_id`); who works on it is a snapshot taken when the offer is sent (`freelance_offer_team_members`) and copied to the agreement when it
 * starts (`freelance_agreement_team_members`); a rating of a team's work carries the team (`freelance_reviews.team_id`, D3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_offers', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('freelancer_id')->constrained('freelance_teams')->nullOnDelete();
            // The team's offers of a month are counted by this.
            $table->index(['team_id', 'created_at']);
        });
        Schema::table('freelance_agreements', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('freelancer_id')->constrained('freelance_teams')->nullOnDelete();
            $table->index(['team_id', 'status']);
        });
        Schema::table('freelance_reviews', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->constrained('freelance_teams')->nullOnDelete();
        });

        foreach (['freelance_offer_team_members' => 'offer_id', 'freelance_agreement_team_members' => 'agreement_id'] as $name => $owner) {
            Schema::create($name, function (Blueprint $table) use ($owner, $name) {
                $table->id();
                $table->foreignId($owner)->constrained($owner === 'offer_id' ? 'freelance_offers' : 'freelance_agreements')->cascadeOnDelete();
                $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
                $table->string('role', 10)->comment('pm | executor');
                $table->string('responsibility', 200)->nullable();
                $table->timestamps();

                // Named by hand: the default name of the agreement table's is over MySQL's 64 letters.
                $table->unique([$owner, 'freelancer_id'], $name.'_pair_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_agreement_team_members');
        Schema::dropIfExists('freelance_offer_team_members');
        Schema::table('freelance_reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
        });
        // The foreign key goes first: MySQL keeps it on our composite index and will not drop that while it is needed.
        Schema::table('freelance_agreements', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropIndex(['team_id', 'status']);
            $table->dropColumn('team_id');
        });
        Schema::table('freelance_offers', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropIndex(['team_id', 'created_at']);
            $table->dropColumn('team_id');
        });
    }
};
