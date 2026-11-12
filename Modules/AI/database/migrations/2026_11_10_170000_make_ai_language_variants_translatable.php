<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI language variants (dialects / styles) become a catalog entity like countries (same CRUD, language tabs, trash):
 * - the name (what the admin and the user's language settings read) moves to ai_language_variant_translations
 *   (one row per stored language); the code and the style are technical and stay;
 * - is_active is renamed status (the catalog's status column: filters, toggle, counts);
 * - soft deletes (trash, restore, force delete). A trashed variant is never offered to users nor used by the chat
 *   (AiChatLanguageResolver), like a switched-off one.
 *
 * Existing text is copied into every stored language so no variant shows up blank; the admin then translates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_language_variant_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_language_variant_id')
                ->constrained('ai_language_variants', 'id', 'ai_language_variant_translations_variant_fk')
                ->cascadeOnDelete()
                ->comment('اللهجة/الأسلوب');
            $table->string('locale', 10)->comment('اللغة');
            $table->string('name')->comment('اسم اللهجة/الأسلوب للعرض (مثال: العربية الفصحى، اللهجة المصرية)');
            $table->timestamps();

            $table->unique(['ai_language_variant_id', 'locale'], 'ai_language_variant_translations_variant_locale_unique');
        });

        $locales = $this->storedLocales();

        DB::table('ai_language_variants')->orderBy('id')->each(function ($variant) use ($locales) {
            foreach ($locales as $locale) {
                DB::table('ai_language_variant_translations')->insert([
                    'ai_language_variant_id' => $variant->id,
                    'locale' => $locale,
                    'name' => $variant->name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('ai_language_variants', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_language_variants', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_language_variants', function (Blueprint $table) {
            $table->string('name')->default('')->after('code');
            $table->dropSoftDeletes();
        });

        Schema::table('ai_language_variants', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });

        $locale = config('app.locale', 'ar');

        DB::table('ai_language_variants')->orderBy('id')->each(function ($variant) use ($locale) {
            $translation = DB::table('ai_language_variant_translations')->where('ai_language_variant_id', $variant->id)
                ->orderByRaw('locale = ? desc', [$locale])->first();

            if ($translation) {
                DB::table('ai_language_variants')->where('id', $variant->id)->update(['name' => $translation->name]);
            }
        });

        Schema::dropIfExists('ai_language_variant_translations');
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
