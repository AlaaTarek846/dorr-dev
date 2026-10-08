<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paid hosting of a finished site: plans (monthly/yearly, price per
     * country), the subscription that publishes one project on a name, and
     * its payment history.
     */
    public function up(): void
    {
        Schema::create('ai_site_hosting_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 60)->unique();
            $table->string('period', 8)->comment('monthly أو yearly');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_site_hosting_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('ai_site_hosting_plans')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('price', 12, 2);
            $table->timestamps();

            $table->unique(['plan_id', 'country_id']);
        });

        Schema::create('ai_site_hostings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('ai_site_projects')->cascadeOnDelete();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('plan_id')->constrained('ai_site_hosting_plans');
            $table->string('subdomain', 63)->unique()->comment('الاسم المحجوز، يفضل محجوز حتى بعد الإلغاء لحد الحذف');
            $table->string('status', 16)->default('active')->comment('active, grace, suspended, cancelled');
            $table->unsignedBigInteger('published_version_id')->nullable()->comment('النسخة المنشورة للجمهور');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8);
            $table->string('period', 8);
            $table->unsignedBigInteger('country_id')->nullable()->comment('دولة السعر وقت الاشتراك، تُستخدم في التجديد');
            $table->boolean('auto_renew')->default(true);
            $table->boolean('admin_suspended')->default(false)->comment('إيقاف من الأدمن (إساءة)، لا يرفعه تجديد العميل');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index(['status', 'ends_at']);
        });

        Schema::create('ai_site_hosting_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hosting_id')->constrained('ai_site_hostings')->cascadeOnDelete();
            $table->string('kind', 12)->comment('initial أو renewal');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8);
            $table->string('wallet_operation_id')->nullable();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_site_hosting_payments');
        Schema::dropIfExists('ai_site_hostings');
        Schema::dropIfExists('ai_site_hosting_plan_prices');
        Schema::dropIfExists('ai_site_hosting_plans');
    }
};
