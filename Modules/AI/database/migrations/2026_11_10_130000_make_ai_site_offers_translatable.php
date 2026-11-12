<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Website-builder offers (site offers, edit packs, the source-download switch) become a catalog entity like
 * countries (same CRUD, language tabs, trash):
 * - name / description move to ai_site_offer_translations (one row per stored language);
 * - is_active is renamed status (the catalog's status column: filters, toggle, counts);
 * - soft deletes (trash, restore, force delete).
 *
 * Existing text is copied into every stored language so no offer shows up blank; the admin then translates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_site_offer_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_site_offer_id')->constrained('ai_site_offers')->cascadeOnDelete()->comment('العرض');
            $table->string('locale', 10)->comment('اللغة');
            $table->string('name')->comment('اسم العرض كما يراه العميل');
            $table->text('description')->nullable()->comment('وصف العرض في التطبيق');
            $table->timestamps();

            $table->unique(['ai_site_offer_id', 'locale']);
        });

        $locales = $this->storedLocales();

        DB::table('ai_site_offers')->orderBy('id')->each(function ($offer) use ($locales) {
            foreach ($locales as $locale) {
                DB::table('ai_site_offer_translations')->insert([
                    'ai_site_offer_id' => $offer->id,
                    'locale' => $locale,
                    'name' => $offer->name,
                    'description' => $offer->description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('ai_site_offers', function (Blueprint $table) {
            $table->renameColumn('is_active', 'status');
        });

        Schema::table('ai_site_offers', function (Blueprint $table) {
            $table->dropColumn(['name', 'description']);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_site_offers', function (Blueprint $table) {
            $table->string('name')->default('')->after('id');
            $table->text('description')->nullable()->after('kind');
            $table->dropSoftDeletes();
        });

        Schema::table('ai_site_offers', function (Blueprint $table) {
            $table->renameColumn('status', 'is_active');
        });

        $locale = config('app.locale', 'ar');

        DB::table('ai_site_offers')->orderBy('id')->each(function ($offer) use ($locale) {
            $translation = DB::table('ai_site_offer_translations')->where('ai_site_offer_id', $offer->id)
                ->orderByRaw('locale = ? desc', [$locale])->first();

            if ($translation) {
                DB::table('ai_site_offers')->where('id', $offer->id)->update([
                    'name' => $translation->name,
                    'description' => $translation->description,
                ]);
            }
        });

        Schema::dropIfExists('ai_site_offer_translations');
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
