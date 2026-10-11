<?php

namespace Modules\Chat\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Models\ChatCategory;

/**
 * The categories channels and merchant portals pick from, each with its own icon (a Remix Icon
 * glyph — Apache-2.0 — on a coloured tile, in `category-icons/`). Safe to run again: a category is
 * matched by its English name, gets its icon only if it has none, and the admin's edits stay.
 */
class ChatCategorySeeder extends Seeder
{
    /** icon file => [English, Arabic] */
    private const CATEGORIES = [
        'news' => ['News', 'أخبار'],
        'sports' => ['Sports', 'رياضة'],
        'entertainment' => ['Entertainment', 'ترفيه'],
        'technology' => ['Technology', 'تقنية'],
        'business' => ['Business & economy', 'أعمال واقتصاد'],
        'education' => ['Education', 'تعليم'],
        'health' => ['Health', 'صحة'],
        'religion' => ['Religion', 'ديني'],
        'food' => ['Food & restaurants', 'أكل ومطاعم'],
        'travel' => ['Travel & tourism', 'سفر وسياحة'],
        'shopping' => ['Shopping', 'تسوق'],
        'fashion' => ['Fashion & beauty', 'موضة وجمال'],
        'cars' => ['Cars', 'سيارات'],
        'gaming' => ['Gaming', 'ألعاب'],
        'family' => ['Family & kids', 'عائلة وأطفال'],
        'real_estate' => ['Real estate', 'عقارات'],
        'services' => ['Services', 'خدمات'],
        'art' => ['Art & culture', 'فن وثقافة'],
        'music' => ['Music', 'موسيقى'],
        'stores' => ['Stores', 'متاجر'],
        'other' => ['Other', 'أخرى'],
    ];

    public function run(): void
    {
        $order = 0;
        foreach (self::CATEGORIES as $icon => [$en, $ar]) {
            $order++;
            $id = DB::table('chat_category_translations')->where('locale', 'en')->where('name', $en)->value('chat_category_id');
            $category = $id ? ChatCategory::query()->find($id) : null;

            if ($category === null) {
                $category = ChatCategory::query()->create(['sort_order' => $order, 'status' => true]);
                $category->translations()->createMany([['locale' => 'en', 'name' => $en], ['locale' => 'ar', 'name' => $ar]]);
            }

            $file = __DIR__.'/category-icons/'.$icon.'.svg';
            if ($category->getFirstMedia(ChatCategory::ICON) === null && is_file($file)) {
                $category->addMedia($file)->preservingOriginal()->usingFileName($icon.'.svg')->usingName($en)->toMediaCollection(ChatCategory::ICON);
            }
        }
    }
}
