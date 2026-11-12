<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI language evaluations become a catalog entity like countries (same CRUD, language tabs, trash):
 * - the name (what the admin reads) moves to ai_language_evaluation_translations (one row per stored language);
 *   the notes are free-text admin notes and stay untranslated, like the numbers;
 * - the existing `status` column is NOT the catalog's on/off switch - it is the evaluation's result
 *   (pending / running / passed / failed) - so it is renamed evaluation_status (same values, same meaning), and a
 *   new catalog `status` boolean (on/off: filters, toggle, counts) is added, every existing row switched on;
 * - soft deletes (trash, restore, force delete).
 *
 * Existing text is copied into every stored language so no evaluation shows up blank; the admin then translates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_language_evaluation_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_language_evaluation_id')
                ->constrained('ai_language_evaluations', 'id', 'ai_language_evaluation_translations_evaluation_fk')
                ->cascadeOnDelete()
                ->comment('مجموعة اختبار اللغة');
            $table->string('locale', 10)->comment('اللغة');
            $table->string('name')->comment('اسم مجموعة الاختبار (مثال: Arabic Core Accuracy Suite)');
            $table->timestamps();

            $table->unique(['ai_language_evaluation_id', 'locale'], 'ai_language_evaluation_translations_eval_locale_unique');
        });

        $locales = $this->storedLocales();

        DB::table('ai_language_evaluations')->orderBy('id')->each(function ($evaluation) use ($locales) {
            foreach ($locales as $locale) {
                DB::table('ai_language_evaluation_translations')->insert([
                    'ai_language_evaluation_id' => $evaluation->id,
                    'locale' => $locale,
                    'name' => $evaluation->name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('ai_language_evaluations', function (Blueprint $table) {
            $table->renameColumn('status', 'evaluation_status');
        });

        Schema::table('ai_language_evaluations', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->boolean('status')->default(true)->after('notes')->comment('هل مجموعة الاختبار مفعّلة؟ (زرار التشغيل في الشاشة)');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_language_evaluations', function (Blueprint $table) {
            $table->dropColumn('status');
            $table->dropSoftDeletes();
            $table->string('name')->default('')->after('id');
        });

        Schema::table('ai_language_evaluations', function (Blueprint $table) {
            $table->renameColumn('evaluation_status', 'status');
        });

        $locale = config('app.locale', 'ar');

        DB::table('ai_language_evaluations')->orderBy('id')->each(function ($evaluation) use ($locale) {
            $translation = DB::table('ai_language_evaluation_translations')->where('ai_language_evaluation_id', $evaluation->id)
                ->orderByRaw('locale = ? desc', [$locale])->first();

            if ($translation) {
                DB::table('ai_language_evaluations')->where('id', $evaluation->id)->update(['name' => $translation->name]);
            }
        });

        Schema::dropIfExists('ai_language_evaluation_translations');
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
