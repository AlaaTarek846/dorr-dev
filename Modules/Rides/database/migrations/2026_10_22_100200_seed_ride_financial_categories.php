<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger lines every completed ride writes (docs/rides-structure.md §10) — seeded here so a
 * deploy that doesn't re-run the seeders still has them. The tax has its own category,
 * «ضريبة التوصيل» (owner's decision, 2026-10-13).
 */
return new class extends Migration
{
    private const CATEGORIES = [
        ['trip_commissions', 'income', 'Ride commissions', 'عمولات الرحلات'],
        ['trip_taxes', 'income', 'Ride tax', 'ضريبة التوصيل'],
        ['discount_expenses', 'expense', 'Ride discounts', 'خصومات الرحلات'],
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
