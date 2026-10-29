<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account verification — the foundation (docs/verification_tasks.md, batch 0): the settings the
 * admin edits, the documents a request asks for, and the log of every notification it sent.
 *
 * The column defaults are the owner's starting values (the "القيم الافتراضية" table): the admin
 * changes any of them from the dashboard; a change applies to what comes next, never to a request
 * or a subscription already running.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_verification_settings', function (Blueprint $table) {
            $table->id();
            // Both tracks start switched off: the admin opens them when the packages and staff are ready.
            $table->boolean('paid_enabled')->default(false);
            $table->boolean('celebrity_enabled')->default(false);

            $table->unsignedSmallInteger('review_deadline_days')->default(7)->comment('مهلة رد الإدارة على الطلب');
            $table->unsignedSmallInteger('completion_deadline_days')->default(14)->comment('مهلة استكمال المستخدم');
            $table->unsignedSmallInteger('deadline_warning_hours')->default(24)->comment('التنبيه قبل انتهاء المهلة');
            $table->unsignedSmallInteger('escalation_after_hours')->default(24)->comment('التصعيد بعد تجاوز المهلة');

            $table->string('paid_activation', 32)->default('after_approval_and_payment');
            $table->string('celebrity_activation', 32)->default('after_proof_approved');

            $table->json('renewal_reminder_days')->nullable()->comment('null = 7 و 1');
            $table->unsignedSmallInteger('subscription_grace_days')->default(3);

            $table->string('exemption_default_type', 16)->default('full')->comment('full · partial · fixed');
            $table->unsignedSmallInteger('exemption_default_months')->default(6);
            $table->unsignedSmallInteger('exemption_warning_days')->default(14);
            $table->unsignedSmallInteger('exemption_grace_days')->default(7);

            $table->unsignedSmallInteger('ad_default_duration_days')->default(30)->comment('مدة بقاء الإعلان لو الاتفاق ما حددش');
            $table->unsignedSmallInteger('proof_deadline_days')->default(3)->comment('مهلة رفع إثبات الإعلان بعد موعده');

            $table->unsignedSmallInteger('refund_window_hours')->default(48)->comment('استرداد كامل لو العلامة ما اتفعلتش');
            $table->boolean('refund_on_rejection')->default(true);
            $table->boolean('refund_on_cancel_before_decision')->default(true);

            $table->unsignedSmallInteger('appeal_window_days')->default(7);
            $table->unsignedSmallInteger('appeal_response_days')->default(7);

            $table->boolean('referral_requires_phone_verification')->default(true);
            $table->timestamps();
        });

        Schema::create('chat_verification_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();
            $table->json('tracks')->comment('paid · celebrity');
            $table->boolean('is_required')->default(true);
            $table->boolean('needs_back_side')->default(false);
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('chat_verification_document_type_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_verification_document_type_id')->constrained('chat_verification_document_types', 'id', 'chat_vdt_trans_type_fk')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 191);
            $table->string('instructions', 1000)->nullable();
            $table->unique(['chat_verification_document_type_id', 'locale'], 'chat_vdt_locale_unique');
        });

        // Every notification a verification event sent, to the account or to the staff (REQ-09-07).
        Schema::create('chat_verification_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('event', 64);
            $table->string('audience', 8)->comment('user · admin');
            $table->string('recipient_type', 32);
            $table->unsignedBigInteger('recipient_id');
            $table->string('subject_type', 64)->nullable()->comment('الطلب أو الاشتراك أو الإعفاء…');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->boolean('push')->default(true);
            $table->string('status', 12)->default('sent')->comment('sent · failed');
            $table->string('error', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            // Short names: MySQL allows 64 characters for an index name.
            $table->index(['subject_type', 'subject_id'], 'chat_verif_notif_subject_idx');
            $table->index(['recipient_type', 'recipient_id'], 'chat_verif_notif_recipient_idx');
            $table->index(['event', 'created_at'], 'chat_verif_notif_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_verification_notifications');
        Schema::dropIfExists('chat_verification_document_type_translations');
        Schema::dropIfExists('chat_verification_document_types');
        Schema::dropIfExists('chat_verification_settings');
    }
};
