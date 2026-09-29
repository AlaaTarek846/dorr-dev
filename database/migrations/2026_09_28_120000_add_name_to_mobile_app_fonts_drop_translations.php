<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_app_fonts', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->after('slug');
        });

        if (Schema::hasTable('mobile_app_font_translations')) {
            $namesByFont = DB::table('mobile_app_font_translations')
                ->select('mobile_app_font_id', 'locale', 'name')
                ->orderBy('mobile_app_font_id')
                ->orderByRaw("CASE WHEN locale = 'en' THEN 0 ELSE 1 END")
                ->get()
                ->groupBy('mobile_app_font_id');

            foreach ($namesByFont as $fontId => $rows) {
                $name = $rows->first()?->name;
                if ($name !== null && $name !== '') {
                    DB::table('mobile_app_fonts')->where('id', $fontId)->update(['name' => $name]);
                }
            }
        }

        DB::table('mobile_app_fonts')
            ->where(fn ($q) => $q->whereNull('name')->orWhere('name', ''))
            ->orderBy('id')
            ->pluck('slug', 'id')
            ->each(function (string $slug, int $id): void {
                DB::table('mobile_app_fonts')->where('id', $id)->update(['name' => $slug]);
            });

        Schema::table('mobile_app_fonts', function (Blueprint $table) {
            $table->unique('name');
        });

        Schema::dropIfExists('mobile_app_font_translations');
    }

    public function down(): void
    {
        Schema::create('mobile_app_font_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mobile_app_font_id')
                ->constrained('mobile_app_fonts')
                ->cascadeOnDelete();
            $table->string('locale');
            $table->string('name');
            $table->timestamps();

            $table->unique(['mobile_app_font_id', 'locale']);
        });

        $fonts = DB::table('mobile_app_fonts')->select('id', 'name')->get();
        foreach ($fonts as $font) {
            foreach (['en', 'ar'] as $locale) {
                DB::table('mobile_app_font_translations')->insert([
                    'mobile_app_font_id' => $font->id,
                    'locale' => $locale,
                    'name' => $font->name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('mobile_app_fonts', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->dropColumn('name');
        });
    }
};
