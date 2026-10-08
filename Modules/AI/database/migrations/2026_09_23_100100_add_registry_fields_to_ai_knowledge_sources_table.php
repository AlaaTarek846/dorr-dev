<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the central-registry fields required by the v2.0 requirements
     * doc, section 5.1 (domain, country, publisher, authority, publish/
     * effective/fetch dates, approval status) on top of the classification
     * + versioning fields Phase 11 already covers.
     */
    public function up(): void
    {
        Schema::table('ai_knowledge_sources', function (Blueprint $table) {
            $table->string('domain')->nullable()->after('name')
                ->comment('التخصص اللي المعرفة دي بتخدمه: قانون / صحة / تعليم / عام... يُستخدم في فلترة الاسترجاع حسب المسار');

            $table->string('country_code', 2)->nullable()->after('domain')
                ->comment('الدولة اللي المصدر ده بيسري عليها أو صادر منها، لو مرتبط بدولة معينة');

            $table->string('publisher')->nullable()->after('country_code')
                ->comment('الجهة الناشرة للمحتوى الأصلي');

            $table->string('authority')->nullable()->after('publisher')
                ->comment('الجهة أو السلطة الرسمية اللي المحتوى معتمد منها، لو ينطبق');

            $table->timestamp('published_at')->nullable()->after('authority')
                ->comment('تاريخ نشر المحتوى الأصلي');

            $table->timestamp('effective_at')->nullable()->after('published_at')
                ->comment('تاريخ سريان المحتوى (مهم للمسار القانوني: نص اتلغى ولا لسه ساري)');

            $table->timestamp('fetched_at')->nullable()->after('effective_at')
                ->comment('امتى النظام جلب/استوعب المحتوى ده فعليًا');

            $table->string('approval_status', 20)->default('pending')->after('is_active')
                ->comment('pending (لسه محتاج مراجعة أدمن) / approved (معتمد ويُستخدم في الاسترجاع) / rejected (مرفوض) / deprecated (كان معتمد وبقى قديم)');

            $table->index(['approval_status', 'is_active'], 'ai_knowledge_sources_approval_active_index');
            $table->index('domain');
        });
    }

    public function down(): void
    {
        Schema::table('ai_knowledge_sources', function (Blueprint $table) {
            $table->dropIndex('ai_knowledge_sources_approval_active_index');
            $table->dropIndex(['domain']);
            $table->dropColumn([
                'domain', 'country_code', 'publisher', 'authority',
                'published_at', 'effective_at', 'fetched_at', 'approval_status',
            ]);
        });
    }
};
