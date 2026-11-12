<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI data policies become a catalog entity like countries (same CRUD, language tabs, trash):
 * - name / description (what the admin reads) move to ai_data_policy_translations (one row per stored language);
 *   the classification, retention days and the three switches (consent, minimization, external provider) are technical
 *   and stay;
 * - is_active is renamed status (the catalog's status column: filters, toggle, counts);
 * - soft deletes (trash, restore, force delete). A trashed policy is never read by the retention command or the log
 *   minimization (EnforceAiDataRetention, AiChatService, AiTaskRunner), like a switched-off one.
 *
 * Existing text is copied into every stored language so no policy shows up blank; the admin then translates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_data_policy_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_data_policy_id')->constrained('ai_data_policies')->cascadeOnDelete()->comment('سياسة البيانات');
            $table->string('locale', 10)->comment('اللغة');
            $table->string('name')->comment('اسم السياسة (مثال: سياسة البيانات الشخصية)');
            $table->text('description')->nullable()->comment('وصف السياسة والغرض منها');
            $table->timestamps();

            $table->unique(['ai_data_policy_id', 'locale'], 'ai_data_policy_translations_policy_locale_unique');
        });

        $locales = $this->storedLocales();

        DB::table('ai_data_policies')->orderBy('id')->each(function ($policy) use ($locales) {
            foreach ($locales as $locale) {
                DB::table('ai_data_policy_translations')->insert([
                    'ai_data_policy_id' => $policy->id,
                    'locale' => $locale,
                    'name' => $policy->name,
                    'description' => $policy->description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('ai_data_policies', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_data_policies', function (Blueprint $table) {
            $table->dropColumn(['name', 'description']);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_data_policies', function (Blueprint $table) {
            $table->string('name')->default('')->after('id');
            $table->text('description')->nullable()->after('external_provider_allowed');
            $table->dropSoftDeletes();
        });

        Schema::table('ai_data_policies', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });

        $locale = config('app.locale', 'ar');

        DB::table('ai_data_policies')->orderBy('id')->each(function ($policy) use ($locale) {
            $translation = DB::table('ai_data_policy_translations')->where('ai_data_policy_id', $policy->id)
                ->orderByRaw('locale = ? desc', [$locale])->first();

            if ($translation) {
                DB::table('ai_data_policies')->where('id', $policy->id)->update([
                    'name' => $translation->name,
                    'description' => $translation->description,
                ]);
            }
        });

        Schema::dropIfExists('ai_data_policy_translations');
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
