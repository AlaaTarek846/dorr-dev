<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI domain policies become a catalog entity like countries (same CRUD, language tabs, trash):
 * - name / description (what the admin reads) move to ai_domain_policy_translations (one row per stored language);
 *   domain_key, risk level, the switches, the system prompt addition and the disclaimer (an instruction the model
 *   gets and translates itself) are technical and stay;
 * - is_active is renamed status (the catalog's status column: filters, toggle, counts);
 * - soft deletes (trash, restore, force delete). A trashed policy is never applied (AiDomainClassifier), like a
 *   switched-off one.
 *
 * Existing text is copied into every stored language so no policy shows up blank; the admin then translates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_domain_policy_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_domain_policy_id')->constrained('ai_domain_policies')->cascadeOnDelete()->comment('سياسة المسار');
            $table->string('locale', 10)->comment('اللغة');
            $table->string('name')->comment('اسم المسار يظهر للأدمن في لوحة التحكم');
            $table->text('description')->nullable()->comment('وصف مختصر لنوع الأسئلة اللي بتدخل المسار ده');
            $table->timestamps();

            $table->unique(['ai_domain_policy_id', 'locale'], 'ai_domain_policy_translations_policy_locale_unique');
        });

        $locales = $this->storedLocales();

        DB::table('ai_domain_policies')->orderBy('id')->each(function ($policy) use ($locales) {
            foreach ($locales as $locale) {
                DB::table('ai_domain_policy_translations')->insert([
                    'ai_domain_policy_id' => $policy->id,
                    'locale' => $locale,
                    'name' => $policy->name,
                    'description' => $policy->description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('ai_domain_policies', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_domain_policies', function (Blueprint $table) {
            $table->dropColumn(['name', 'description']);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_domain_policies', function (Blueprint $table) {
            $table->string('name')->default('')->after('domain_key');
            $table->text('description')->nullable()->after('name');
            $table->dropSoftDeletes();
        });

        Schema::table('ai_domain_policies', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });

        $locale = config('app.locale', 'ar');

        DB::table('ai_domain_policies')->orderBy('id')->each(function ($policy) use ($locale) {
            $translation = DB::table('ai_domain_policy_translations')->where('ai_domain_policy_id', $policy->id)
                ->orderByRaw('locale = ? desc', [$locale])->first();

            if ($translation) {
                DB::table('ai_domain_policies')->where('id', $policy->id)->update([
                    'name' => $translation->name,
                    'description' => $translation->description,
                ]);
            }
        });

        Schema::dropIfExists('ai_domain_policy_translations');
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
