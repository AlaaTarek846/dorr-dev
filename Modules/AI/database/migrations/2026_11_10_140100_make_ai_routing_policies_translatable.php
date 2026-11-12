<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI routing policies become a catalog entity like countries (same CRUD, language tabs, trash):
 * - name (what the admin reads) moves to ai_routing_policy_translations (one row per stored language);
 *   scope, country / service codes, strategy and priority are technical values and stay as they are;
 * - is_active is renamed status (the catalog's status column: filters, toggle, counts);
 * - soft deletes (trash, restore, force delete). A trashed policy is never used for routing (AiRoutingEngine).
 *
 * Existing text is copied into every stored language so no policy shows up blank; the admin then translates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_routing_policy_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_routing_policy_id')->constrained('ai_routing_policies')->cascadeOnDelete()->comment('سياسة التوجيه');
            $table->string('locale', 10)->comment('اللغة');
            $table->string('name')->comment('اسم السياسة للعرض في لوحة تحكم الأدمن');
            $table->timestamps();

            // The default name (table + both columns) is 65 characters - over MySQL's 64.
            $table->unique(['ai_routing_policy_id', 'locale'], 'ai_routing_policy_tr_policy_locale_unique');
        });

        $locales = $this->storedLocales();

        DB::table('ai_routing_policies')->orderBy('id')->each(function ($policy) use ($locales) {
            foreach ($locales as $locale) {
                DB::table('ai_routing_policy_translations')->insert([
                    'ai_routing_policy_id' => $policy->id,
                    'locale' => $locale,
                    'name' => $policy->name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('ai_routing_policies', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_routing_policies', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_routing_policies', function (Blueprint $table) {
            $table->string('name')->default('')->after('id');
            $table->dropSoftDeletes();
        });

        Schema::table('ai_routing_policies', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });

        $locale = config('app.locale', 'ar');

        DB::table('ai_routing_policies')->orderBy('id')->each(function ($policy) use ($locale) {
            $translation = DB::table('ai_routing_policy_translations')->where('ai_routing_policy_id', $policy->id)
                ->orderByRaw('locale = ? desc', [$locale])->first();

            if ($translation) {
                DB::table('ai_routing_policies')->where('id', $policy->id)->update(['name' => $translation->name]);
            }
        });

        Schema::dropIfExists('ai_routing_policy_translations');
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
