<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Driver\Enums\DocumentStatus;

/** Uploaded documents and per-service eligibility (docs/rides-structure.md §1.4, §1.7). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->cascadeOnDelete()->comment('لو المستند خاص بمركبة');
            $table->foreignId('document_type_id')->constrained('driver_document_types')->restrictOnDelete();
            $table->string('number', 64)->nullable()->comment('رقم المستند');
            $table->date('expires_at')->nullable()->comment('تاريخ الانتهاء');
            $table->string('status', 16)->default(DocumentStatus::Pending->value);
            $table->string('rejection_reason')->nullable()->comment('بيظهر للسواق');
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'document_type_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('driver_service_eligibility', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained('service_categories')->cascadeOnDelete()->comment('الخدمة: ركاب، وبعدين توصيل أو طرود');
            $table->string('status', 16)->comment('eligible / ineligible / suspended / pending');
            $table->string('reason')->nullable();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['driver_id', 'service_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_service_eligibility');
        Schema::dropIfExists('driver_documents');
    }
};
