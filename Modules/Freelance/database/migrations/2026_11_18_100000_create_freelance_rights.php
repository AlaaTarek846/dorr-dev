<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-L13: ownership and licences. The freelancer says, on an offer or a ready-made service, who owns the work after it is paid and what
 * the client may do with it; the agreement keeps those terms (and the standard text in force that day) as they were. A part's rights
 * pass to the client when it is approved and paid — before that they only look at it. The freelancer confirms the work is theirs with
 * every delivery, and may show a finished work in their portfolio only with the client's say-so. The staff keep the standard texts per
 * country and review the reports of a work someone says is theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_rights_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained('countries')->cascadeOnDelete()->comment('null = the text for every country that has none of its own');
            $table->string('model', 16)->comment('transfer | license | retained');
            $table->string('title_ar', 120);
            $table->string('title_en', 120);
            $table->text('body_ar');
            $table->text('body_en');
            $table->unsignedSmallInteger('version')->default(1)->comment('goes up on every edit; an agreement keeps the text it was made with');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'model']);
        });

        Schema::table('freelance_offers', function (Blueprint $table) {
            $table->json('rights')->nullable()->after('answers')->comment('{model, commercial, exclusive, term_months, territory, portfolio} as the freelancer offered');
        });

        Schema::table('freelance_services', function (Blueprint $table) {
            $table->json('rights')->nullable()->after('requirements')->comment('the same terms, for every order on this service');
        });

        Schema::table('freelance_agreements', function (Blueprint $table) {
            $table->json('rights')->nullable()->after('money')->comment('the agreed terms + the standard text of that day; null = made without rights');
            $table->boolean('portfolio_allowed')->default(false)->after('rights')->comment('the client lets the freelancer show the work in their portfolio');
            $table->timestamp('portfolio_asked_at')->nullable()->after('portfolio_allowed');
        });

        Schema::table('freelance_deliveries', function (Blueprint $table) {
            $table->json('rights')->nullable()->after('note')->comment('{confirmed_at, materials: [{name, source, license}]} - the freelancer\'s word that the work is theirs');
        });

        Schema::table('freelancer_portfolio_items', function (Blueprint $table) {
            $table->foreignId('agreement_id')->nullable()->after('freelancer_id')->constrained('freelance_agreements')->nullOnDelete()->comment('the finished work this item shows, with the client\'s say-so');
        });

        Schema::create('freelance_rights_claims', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('subject_type', 16)->comment('portfolio_item | service');
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_title', 160)->comment('what it was called when reported');
            $table->foreignId('freelancer_id')->nullable()->constrained('freelancers')->nullOnDelete()->comment('whose work it is');
            $table->string('reporter_type', 12)->comment('client | external');
            $table->unsignedBigInteger('reporter_id')->nullable();
            $table->string('reporter_name', 120)->nullable();
            $table->string('reporter_contact', 160)->nullable();
            $table->text('details');
            $table->string('status', 12)->default('open')->comment('open | upheld | dismissed');
            $table->text('decision_note')->nullable();
            $table->unsignedBigInteger('decided_by_admin_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_rights_claims');
        Schema::table('freelancer_portfolio_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agreement_id');
        });
        Schema::table('freelance_deliveries', function (Blueprint $table) {
            $table->dropColumn('rights');
        });
        Schema::table('freelance_agreements', function (Blueprint $table) {
            $table->dropColumn(['rights', 'portfolio_allowed', 'portfolio_asked_at']);
        });
        Schema::table('freelance_services', function (Blueprint $table) {
            $table->dropColumn('rights');
        });
        Schema::table('freelance_offers', function (Blueprint $table) {
            $table->dropColumn('rights');
        });
        Schema::dropIfExists('freelance_rights_terms');
    }
};
