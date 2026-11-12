<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI safety policies become a catalog entity like countries (same CRUD, language tabs, trash):
 * - name / description (what the admin reads) move to ai_safety_policy_translations (one row per stored language);
 *   the risk level and the contexts it applies to are technical and stay;
 * - is_active is renamed status (the catalog's status column: filters, toggle, counts);
 * - soft deletes (trash, restore, force delete). The rules of a trashed policy are never applied (AiSafetyGuard),
 *   like those of a switched-off one.
 *
 * Existing text is copied into every stored language so no policy shows up blank; the admin then translates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_safety_policy_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_safety_policy_id')->constrained('ai_safety_policies')->cascadeOnDelete()->comment('سياسة الأمان');
            $table->string('locale', 10)->comment('اللغة');
            $table->string('name')->comment('اسم سياسة الأمان (مثال: منع محاولات الاختراق)');
            $table->text('description')->nullable()->comment('وصف السياسة والغرض منها');
            $table->timestamps();

            $table->unique(['ai_safety_policy_id', 'locale'], 'ai_safety_policy_translations_policy_locale_unique');
        });

        $locales = $this->storedLocales();

        DB::table('ai_safety_policies')->orderBy('id')->each(function ($policy) use ($locales) {
            foreach ($locales as $locale) {
                DB::table('ai_safety_policy_translations')->insert([
                    'ai_safety_policy_id' => $policy->id,
                    'locale' => $locale,
                    'name' => $policy->name,
                    'description' => $policy->description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('ai_safety_policies', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_safety_policies', function (Blueprint $table) {
            $table->dropColumn(['name', 'description']);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_safety_policies', function (Blueprint $table) {
            $table->string('name')->default('')->after('id');
            $table->text('description')->nullable()->after('applies_to');
            $table->dropSoftDeletes();
        });

        Schema::table('ai_safety_policies', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });

        $locale = config('app.locale', 'ar');

        DB::table('ai_safety_policies')->orderBy('id')->each(function ($policy) use ($locale) {
            $translation = DB::table('ai_safety_policy_translations')->where('ai_safety_policy_id', $policy->id)
                ->orderByRaw('locale = ? desc', [$locale])->first();

            if ($translation) {
                DB::table('ai_safety_policies')->where('id', $policy->id)->update([
                    'name' => $translation->name,
                    'description' => $translation->description,
                ]);
            }
        });

        Schema::dropIfExists('ai_safety_policy_translations');
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
