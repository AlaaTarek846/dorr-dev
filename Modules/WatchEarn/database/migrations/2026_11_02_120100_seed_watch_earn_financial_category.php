<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger line for the platform's share of each completed view (and of an unclaimed interaction share when the
 * country keeps it) — seeded here so a deploy that doesn't re-run the seeders still has it.
 */
return new class extends Migration
{
    private const CATEGORIES = [
        ['watch_earn_revenue', 'income', 'Watch & Earn revenue', 'إيرادات شاهد واكسب'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('financial_categories')) {
            return;
        }

        foreach (self::CATEGORIES as [$slug, $type, $en, $ar]) {
            if (DB::table('financial_categories')->where('slug', $slug)->exists()) {
                continue;
            }
            $id = DB::table('financial_categories')->insertGetId([
                'slug' => $slug, 'type' => $type, 'is_system' => true, 'status' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (Schema::hasTable('financial_category_translations')) {
                foreach (['en' => $en, 'ar' => $ar] as $locale => $name) {
                    DB::table('financial_category_translations')->insert([
                        'financial_category_id' => $id, 'locale' => $locale, 'name' => $name,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Ledger categories may already be referenced by entries: kept on rollback.
    }
};
