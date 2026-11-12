<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI intents become a catalog entity like countries (same CRUD, language tabs, trash):
 * - name / description (what the admin reads) move to ai_intent_translations (one row per stored language);
 *   `key` is the technical code the classifier and the routing rules use and stays as it is;
 * - is_active is renamed status (the catalog's status column: filters, toggle, counts);
 * - soft deletes (trash, restore, force delete). A trashed intent is never classified (AiIntentClassifier).
 *
 * Existing text is copied into every stored language so no intent shows up blank; the admin then translates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_intent_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_intent_id')->constrained('ai_intents')->cascadeOnDelete()->comment('نوع الطلب');
            $table->string('locale', 10)->comment('اللغة');
            $table->string('name')->comment('اسم نوع الطلب يظهر للأدمن في لوحة التحكم');
            $table->text('description')->nullable()->comment('وصف مختصر يوضح الفرق بين النوع ده وغيره');
            $table->timestamps();

            $table->unique(['ai_intent_id', 'locale'], 'ai_intent_translations_intent_locale_unique');
        });

        $locales = $this->storedLocales();

        DB::table('ai_intents')->orderBy('id')->each(function ($intent) use ($locales) {
            foreach ($locales as $locale) {
                DB::table('ai_intent_translations')->insert([
                    'ai_intent_id' => $intent->id,
                    'locale' => $locale,
                    'name' => $intent->name,
                    'description' => $intent->description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('ai_intents', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_intents', function (Blueprint $table) {
            $table->dropColumn(['name', 'description']);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_intents', function (Blueprint $table) {
            $table->string('name')->default('')->after('key');
            $table->text('description')->nullable()->after('name');
            $table->dropSoftDeletes();
        });

        Schema::table('ai_intents', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });

        $locale = config('app.locale', 'ar');

        DB::table('ai_intents')->orderBy('id')->each(function ($intent) use ($locale) {
            $translation = DB::table('ai_intent_translations')->where('ai_intent_id', $intent->id)
                ->orderByRaw('locale = ? desc', [$locale])->first();

            if ($translation) {
                DB::table('ai_intents')->where('id', $intent->id)->update([
                    'name' => $translation->name,
                    'description' => $translation->description,
                ]);
            }
        });

        Schema::dropIfExists('ai_intent_translations');
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
