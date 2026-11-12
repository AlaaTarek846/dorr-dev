<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI safety rules become a catalog entity like countries (same CRUD, language tabs, trash):
 * - name (what the admin reads) moves to ai_safety_rule_translations (one row per stored language); the condition
 *   (keywords / risk score JSON), the action and the priority are technical and stay;
 * - is_active is renamed status (the catalog's status column: filters, toggle, counts);
 * - soft deletes (trash, restore, force delete). A trashed rule is never applied (AiSafetyGuard), like a
 *   switched-off one.
 *
 * Existing text is copied into every stored language so no rule shows up blank; the admin then translates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_safety_rule_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_safety_rule_id')->constrained('ai_safety_rules')->cascadeOnDelete()->comment('قاعدة الأمان');
            $table->string('locale', 10)->comment('اللغة');
            $table->string('name')->comment('اسم القاعدة (مثال: كشف طلب حذف ملفات جماعي)');
            $table->timestamps();

            $table->unique(['ai_safety_rule_id', 'locale'], 'ai_safety_rule_translations_rule_locale_unique');
        });

        $locales = $this->storedLocales();

        DB::table('ai_safety_rules')->orderBy('id')->each(function ($rule) use ($locales) {
            foreach ($locales as $locale) {
                DB::table('ai_safety_rule_translations')->insert([
                    'ai_safety_rule_id' => $rule->id,
                    'locale' => $locale,
                    'name' => $rule->name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('ai_safety_rules', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_safety_rules', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_safety_rules', function (Blueprint $table) {
            $table->string('name')->default('')->after('safety_policy_id');
            $table->dropSoftDeletes();
        });

        Schema::table('ai_safety_rules', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });

        $locale = config('app.locale', 'ar');

        DB::table('ai_safety_rules')->orderBy('id')->each(function ($rule) use ($locale) {
            $translation = DB::table('ai_safety_rule_translations')->where('ai_safety_rule_id', $rule->id)
                ->orderByRaw('locale = ? desc', [$locale])->first();

            if ($translation) {
                DB::table('ai_safety_rules')->where('id', $rule->id)->update(['name' => $translation->name]);
            }
        });

        Schema::dropIfExists('ai_safety_rule_translations');
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
