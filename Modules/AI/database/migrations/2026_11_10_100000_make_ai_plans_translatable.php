<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI plans become a catalog entity like countries (same CRUD, language tabs, trash):
 * - name / description / badge / features move to ai_plan_translations (one row per stored language);
 * - is_active is renamed status (the catalog's status column: filters, toggle, counts);
 * - soft deletes (trash, restore, force delete).
 *
 * Existing text is copied into every stored language so no plan shows up blank; the admin then translates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_plan_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_plan_id')->constrained('ai_plans')->cascadeOnDelete()->comment('الباقة');
            $table->string('locale', 10)->comment('اللغة');
            $table->string('name')->comment('اسم الباقة');
            $table->text('description')->nullable()->comment('وصف الباقة في صفحة الأسعار');
            $table->string('badge')->nullable()->comment('شارة العرض مثل: الأكثر طلبًا');
            $table->json('features')->nullable()->comment('مميزات الباقة (قائمة نصوص)');
            $table->timestamps();

            $table->unique(['ai_plan_id', 'locale']);
        });

        $locales = $this->storedLocales();

        DB::table('ai_plans')->orderBy('id')->each(function ($plan) use ($locales) {
            foreach ($locales as $locale) {
                DB::table('ai_plan_translations')->insert([
                    'ai_plan_id' => $plan->id,
                    'locale' => $locale,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'badge' => $plan->badge,
                    'features' => $plan->features,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('ai_plans', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_plans', function (Blueprint $table) {
            $table->dropColumn(['name', 'description', 'badge', 'features']);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_plans', function (Blueprint $table) {
            $table->string('name')->default('')->after('id');
            $table->text('description')->nullable()->after('code');
            $table->string('badge')->nullable();
            $table->json('features')->nullable();
            $table->dropSoftDeletes();
        });

        Schema::table('ai_plans', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });

        $locale = config('app.locale', 'ar');

        DB::table('ai_plans')->orderBy('id')->each(function ($plan) use ($locale) {
            $translation = DB::table('ai_plan_translations')->where('ai_plan_id', $plan->id)
                ->orderByRaw('locale = ? desc', [$locale])->first();

            if ($translation) {
                DB::table('ai_plans')->where('id', $plan->id)->update([
                    'name' => $translation->name,
                    'description' => $translation->description,
                    'badge' => $translation->badge,
                    'features' => $translation->features,
                ]);
            }
        });

        Schema::dropIfExists('ai_plan_translations');
    }

    /**
     * @return list<string>
     */
    private function storedLocales(): array
    {
        $codes = Schema::hasTable('languages')
            ? DB::table('languages')->where('status', true)->where('stores_translation', true)->pluck('code')
                ->map(fn ($code) => strtolower((string) $code))->all()
            : [];

        return $codes !== [] ? array_values(array_unique($codes)) : ['ar', 'en'];
    }
};
