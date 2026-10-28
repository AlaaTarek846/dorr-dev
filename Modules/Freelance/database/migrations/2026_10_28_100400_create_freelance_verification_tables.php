<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FL-107 / FL-108 / FL-110: verifying a freelancer by hand. The admin decides which identity documents a country
 * asks for (and from citizens, residents or both); the freelancer uploads them (private files) and submits a request;
 * the admin approves, rejects or asks for changes. Every request is kept, so a second try doesn't erase the first.
 *
 * The account statuses get the names of that journey: incomplete → registered, pending_verification → profile_ready,
 * active → verified.
 */
return new class extends Migration
{
    private const RENAMES = ['incomplete' => 'registered', 'pending_verification' => 'profile_ready', 'active' => 'verified'];

    public function up(): void
    {
        Schema::table('freelancers', function (Blueprint $table) {
            $table->string('status', 24)->default('registered')->comment('registered / profile_ready / verification_submitted / verified / verification_rejected / changes_requested / suspended / blocked')->change();
            $table->string('residency', 12)->nullable()->after('country_id')->comment('citizen / resident — which documents the country asks for');
            $table->timestamp('verified_at')->nullable()->after('status_reason');
        });
        foreach (self::RENAMES as $old => $new) {
            DB::table('freelancers')->where('status', $old)->update(['status' => $new]);
        }

        Schema::create('freelance_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();
            $table->boolean('has_number')->default(false);
            $table->boolean('has_expiry')->default(false);
            $table->boolean('needs_back_side')->default(false);
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('freelance_document_type_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelance_document_type_id')->constrained('freelance_document_types', 'id', 'fl_doc_type_trans_type_fk')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 191);
            $table->string('instructions', 1000)->nullable();
            $table->unique(['freelance_document_type_id', 'locale'], 'freelance_doc_type_locale_unique');
        });

        Schema::create('freelance_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_type_id')->constrained('freelance_document_types', 'id', 'fl_doc_req_type_fk')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('residency', 12)->nullable()->comment('null = everyone, citizen / resident');
            $table->boolean('is_required')->default(true);
            $table->timestamps();
            $table->index(['country_id', 'is_required']);
        });

        Schema::create('freelance_verification_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->string('status', 20)->default('submitted')->comment('submitted / approved / rejected / changes_requested');
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admins', 'id', 'fl_vreq_admin_fk')->nullOnDelete();
            $table->string('reason', 500)->nullable()->comment('سبب الرفض أو المطلوب تعديله، بيظهر للمحترف');
            $table->timestamps();
            $table->index(['status', 'submitted_at']);
            $table->index(['freelancer_id', 'id']);
        });

        Schema::create('freelance_verification_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('freelance_document_types', 'id', 'fl_vdoc_type_fk')->restrictOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('freelance_verification_requests', 'id', 'fl_vdoc_request_fk')->nullOnDelete()->comment('the request it was sent with');
            $table->string('number', 64)->nullable();
            $table->date('expires_on')->nullable();
            $table->string('status', 12)->default('draft')->comment('draft / pending / approved / rejected / expired / replaced');
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins', 'id', 'fl_vdoc_admin_fk')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['freelancer_id', 'document_type_id', 'status'], 'freelance_vdoc_lookup');
            $table->index(['status', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_verification_documents');
        Schema::dropIfExists('freelance_verification_requests');
        Schema::dropIfExists('freelance_document_requirements');
        Schema::dropIfExists('freelance_document_type_translations');
        Schema::dropIfExists('freelance_document_types');
        foreach (self::RENAMES as $old => $new) {
            DB::table('freelancers')->where('status', $new)->update(['status' => $old]);
        }
        Schema::table('freelancers', function (Blueprint $table) {
            $table->dropColumn(['residency', 'verified_at']);
            $table->string('status', 24)->default('incomplete')->change();
        });
    }
};
