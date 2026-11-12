<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI feature flags become a catalog entity like countries (same CRUD, language tabs, trash):
 * - description (what the admin reads) moves to ai_feature_flag_translations (one row per stored language);
 *   key, target, provider / model / tool / service keys, country, domain and environment are technical and stay;
 * - is_enabled is renamed status (the catalog's status column: filters, toggle, counts). As before, a flag whose
 *   status is off is the kill switch: it turns its target off (AiFeatureFlagGate);
 * - soft deletes (trash, restore, force delete). A trashed flag is never applied - like a deleted one before.
 *
 * Existing text is copied into every stored language so no flag shows up blank; the admin then translates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_feature_flag_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_feature_flag_id')->constrained('ai_feature_flags')->cascadeOnDelete()->comment('خاصية التشغيل');
            $table->string('locale', 10)->comment('اللغة');
            $table->text('description')->nullable()->comment('وصف يوضح للأدمن الـ flag ده بيقفل إيه وليه');
            $table->timestamps();

            $table->unique(['ai_feature_flag_id', 'locale'], 'ai_feature_flag_translations_flag_locale_unique');
        });

        $locales = $this->storedLocales();

        DB::table('ai_feature_flags')->orderBy('id')->each(function ($flag) use ($locales) {
            foreach ($locales as $locale) {
                DB::table('ai_feature_flag_translations')->insert([
                    'ai_feature_flag_id' => $flag->id,
                    'locale' => $locale,
                    'description' => $flag->description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('ai_feature_flags', function (Blueprint $table) {
            $table->renameColumn('is_enabled', 'status');
        });

        Schema::table('ai_feature_flags', function (Blueprint $table) {
            $table->dropColumn('description');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_feature_flags', function (Blueprint $table) {
            $table->text('description')->nullable()->after('status');
            $table->dropSoftDeletes();
        });

        Schema::table('ai_feature_flags', function (Blueprint $table) {
            $table->renameColumn('status', 'is_enabled');
        });

        $locale = config('app.locale', 'ar');

        DB::table('ai_feature_flags')->orderBy('id')->each(function ($flag) use ($locale) {
            $translation = DB::table('ai_feature_flag_translations')->where('ai_feature_flag_id', $flag->id)
                ->orderByRaw('locale = ? desc', [$locale])->first();

            if ($translation) {
                DB::table('ai_feature_flags')->where('id', $flag->id)->update(['description' => $translation->description]);
            }
        });

        Schema::dropIfExists('ai_feature_flag_translations');
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
