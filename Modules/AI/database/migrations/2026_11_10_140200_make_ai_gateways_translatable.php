<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI gateways become a catalog entity like countries (same CRUD, language tabs, trash):
 * - name (what the admin reads) moves to ai_gateway_translations (one row per stored language);
 *   the environment and the default policy are technical values and stay as they are;
 * - is_active is renamed status (the catalog's status column: filters, toggle, counts);
 * - soft deletes (trash, restore, force delete). A trashed gateway is never used for routing (AiRoutingEngine).
 *
 * Existing text is copied into every stored language so no gateway shows up blank; the admin then translates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_gateway_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_gateway_id')->constrained('ai_gateways')->cascadeOnDelete()->comment('البوابة');
            $table->string('locale', 10)->comment('اللغة');
            $table->string('name')->comment('اسم الـ Gateway للعرض في لوحة تحكم الأدمن');
            $table->timestamps();

            $table->unique(['ai_gateway_id', 'locale'], 'ai_gateway_translations_gateway_locale_unique');
        });

        $locales = $this->storedLocales();

        DB::table('ai_gateways')->orderBy('id')->each(function ($gateway) use ($locales) {
            foreach ($locales as $locale) {
                DB::table('ai_gateway_translations')->insert([
                    'ai_gateway_id' => $gateway->id,
                    'locale' => $locale,
                    'name' => $gateway->name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('ai_gateways', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_gateways', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_gateways', function (Blueprint $table) {
            $table->string('name')->default('')->after('id');
            $table->dropSoftDeletes();
        });

        Schema::table('ai_gateways', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });

        $locale = config('app.locale', 'ar');

        DB::table('ai_gateways')->orderBy('id')->each(function ($gateway) use ($locale) {
            $translation = DB::table('ai_gateway_translations')->where('ai_gateway_id', $gateway->id)
                ->orderByRaw('locale = ? desc', [$locale])->first();

            if ($translation) {
                DB::table('ai_gateways')->where('id', $gateway->id)->update(['name' => $translation->name]);
            }
        });

        Schema::dropIfExists('ai_gateway_translations');
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
