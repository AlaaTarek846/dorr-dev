<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site hosting plans become a catalog entity like countries (same CRUD, language tabs, trash):
 * - name / description move to ai_site_hosting_plan_translations (one row per stored language);
 * - is_active is renamed status (the catalog's status column: filters, toggle, counts);
 * - soft deletes (trash, restore, force delete).
 *
 * Existing text is copied into every stored language so no plan shows up blank; the admin then translates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_site_hosting_plan_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_site_hosting_plan_id')->constrained('ai_site_hosting_plans', 'id', 'ai_site_hosting_plan_tr_plan_foreign')->cascadeOnDelete()->comment('باقة الاستضافة');
            $table->string('locale', 10)->comment('اللغة');
            $table->string('name')->comment('اسم باقة الاستضافة كما يراه العميل');
            $table->text('description')->nullable()->comment('وصف الباقة في التطبيق');
            $table->timestamps();

            $table->unique(['ai_site_hosting_plan_id', 'locale'], 'ai_site_hosting_plan_tr_plan_locale_unique');
        });

        $locales = $this->storedLocales();

        DB::table('ai_site_hosting_plans')->orderBy('id')->each(function ($plan) use ($locales) {
            foreach ($locales as $locale) {
                DB::table('ai_site_hosting_plan_translations')->insert([
                    'ai_site_hosting_plan_id' => $plan->id,
                    'locale' => $locale,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('ai_site_hosting_plans', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_site_hosting_plans', function (Blueprint $table) {
            $table->dropColumn(['name', 'description']);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_site_hosting_plans', function (Blueprint $table) {
            $table->string('name')->default('')->after('id');
            $table->text('description')->nullable()->after('period');
            $table->dropSoftDeletes();
        });

        Schema::table('ai_site_hosting_plans', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });

        $locale = config('app.locale', 'ar');

        DB::table('ai_site_hosting_plans')->orderBy('id')->each(function ($plan) use ($locale) {
            $translation = DB::table('ai_site_hosting_plan_translations')->where('ai_site_hosting_plan_id', $plan->id)
                ->orderByRaw('locale = ? desc', [$locale])->first();

            if ($translation) {
                DB::table('ai_site_hosting_plans')->where('id', $plan->id)->update([
                    'name' => $translation->name,
                    'description' => $translation->description,
                ]);
            }
        });

        Schema::dropIfExists('ai_site_hosting_plan_translations');
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
