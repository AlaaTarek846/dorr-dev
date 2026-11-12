<?php

namespace Modules\Chat\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Models\ChatCategory;
use Modules\Chat\Models\ChatPortal;
use Modules\User\Models\User;

/**
 * Test data for the merchant portals page: 20 listed portals across categories (cars, perfumes, stores, food,
 * technology, travel), with view counts spread out (to see the most-viewed-first order) and a simple logo made
 * here (the brand's initials on a coloured tile — no brand artwork). Local testing only: the names are real brands.
 *
 * The last mobile-app login owns a few (so «My portals» has some); the rest go to other users. Safe to run again:
 * the demo portals (matched by their website) are replaced.
 *
 *   php artisan db:seed --class="Modules\Chat\Database\Seeders\ChatPortalsDemoSeeder"
 */
class ChatPortalsDemoSeeder extends Seeder
{
    /** [English name, Arabic name, English description, Arabic description, website, category (English), tile colour] */
    private const PORTALS = [
        ['Toyota', 'تويوتا', 'New cars, offers and service centres near you.', 'سيارات جديدة وعروض ومراكز صيانة قريبة منك.', 'https://www.toyota.com', 'Cars', '#EB0A1E'],
        ['Mercedes-Benz', 'مرسيدس بنز', 'Luxury cars, test drives and genuine parts.', 'سيارات فاخرة وتجربة قيادة وقطع غيار أصلية.', 'https://www.mercedes-benz.com', 'Cars', '#1F2937'],
        ['BMW', 'بي إم دبليو', 'The ultimate driving machine — models and finance.', 'متعة القيادة — الموديلات وخطط التمويل.', 'https://www.bmw.com', 'Cars', '#1C69D4'],
        ['Hyundai', 'هيونداي', 'Reliable family cars and SUVs at great prices.', 'سيارات عائلية ودفع رباعي بأسعار مميزة.', 'https://www.hyundai.com', 'Cars', '#002C5F'],
        ['Kia', 'كيا', 'Bold design, long warranty, everyday value.', 'تصميم جريء وضمان طويل وقيمة يومية.', 'https://www.kia.com', 'Cars', '#05141F'],
        ['Arabian Oud', 'العربية للعود', 'Oud, bakhoor and Arabian perfumes.', 'عود وبخور وعطور عربية فاخرة.', 'https://www.arabianoud.com', 'Fashion & beauty', '#7C2D12'],
        ['Abdul Samad Al Qurashi', 'عبد الصمد القرشي', 'Heritage perfumes since 1852.', 'عطور عريقة منذ عام 1852.', 'https://www.asqgrp.com', 'Fashion & beauty', '#92400E'],
        ['Chanel', 'شانيل', 'Fragrance, beauty and fashion.', 'عطور ومستحضرات تجميل وأزياء.', 'https://www.chanel.com', 'Fashion & beauty', '#111111'],
        ['Dior', 'ديور', 'Iconic fragrances and makeup.', 'عطور ومكياج من دار ديور.', 'https://www.dior.com', 'Fashion & beauty', '#374151'],
        ['Ajmal', 'أجمل', 'Oriental and modern perfumes for every taste.', 'عطور شرقية وعصرية لكل الأذواق.', 'https://www.ajmalperfume.com', 'Fashion & beauty', '#B45309'],
        ['IKEA', 'ايكيا', 'Furniture and home ideas for every room.', 'أثاث وأفكار للمنزل لكل غرفة.', 'https://www.ikea.com', 'Stores', '#0058A3'],
        ['Carrefour', 'كارفور', 'Groceries and household essentials, delivered.', 'بقالة ومستلزمات منزلية مع التوصيل.', 'https://www.carrefour.com', 'Stores', '#004E9F'],
        ['Noon', 'نون', 'Shop electronics, fashion and more online.', 'تسوّق الإلكترونيات والأزياء وأكثر أونلاين.', 'https://www.noon.com', 'Stores', '#EAB308'],
        ['Amazon', 'أمازون', 'Millions of products with fast delivery.', 'ملايين المنتجات مع توصيل سريع.', 'https://www.amazon.com', 'Stores', '#232F3E'],
        ['Jarir Bookstore', 'مكتبة جرير', 'Books, office supplies and smart devices.', 'كتب وأدوات مكتبية وأجهزة ذكية.', 'https://www.jarir.com', 'Stores', '#C8102E'],
        ['McDonald\'s', 'ماكدونالدز', 'Burgers, meals and offers near you.', 'برجر ووجبات وعروض بالقرب منك.', 'https://www.mcdonalds.com', 'Food & restaurants', '#DA291C'],
        ['Starbucks', 'ستاربكس', 'Coffee, drinks and something sweet.', 'قهوة ومشروبات وحلويات.', 'https://www.starbucks.com', 'Food & restaurants', '#00704A'],
        ['Samsung', 'سامسونج', 'Phones, TVs and home appliances.', 'هواتف وشاشات وأجهزة منزلية.', 'https://www.samsung.com', 'Technology', '#1428A0'],
        ['Xiaomi', 'شاومي', 'Smart phones and smart home at great value.', 'هواتف ذكية ومنزل ذكي بقيمة ممتازة.', 'https://www.mi.com', 'Technology', '#FF6900'],
        ['Emirates', 'طيران الإمارات', 'Book flights to over 140 destinations.', 'احجز رحلاتك إلى أكثر من 140 وجهة.', 'https://www.emirates.com', 'Travel & tourism', '#D71921'],
    ];

    public function run(): void
    {
        // The categories portals pick from (idempotent).
        $this->call(ChatCategorySeeder::class);

        $sites = array_column(self::PORTALS, 4);
        ChatPortal::query()->withTrashed()->whereIn('website_url', $sites)->get()->each(fn (ChatPortal $p) => $p->forceDelete());

        $me = $this->demoUser();
        $others = User::query()->when($me, fn ($q) => $q->whereKeyNot($me->getKey()))->inRandomOrder()->limit(8)->pluck('id')->all();
        $font = base_path('androidApp/app/src/main/res/font/cairo.ttf');

        foreach (self::PORTALS as $i => [$en, $ar, $enDesc, $arDesc, $url, $category, $colour]) {
            // A few are mine (to see «My portals»), the rest someone else's.
            $ownerId = ($me && ($i % 7 === 0 || $others === [])) ? $me->getKey() : ($others[$i % max(count($others), 1)] ?? $me?->getKey());
            if ($ownerId === null) {
                $this->command?->warn('No users — nothing to own the portals.');

                return;
            }
            $portal = ChatPortal::query()->create([
                'uuid' => (string) Str::uuid(),
                'owner_type' => 'user',
                'owner_id' => $ownerId,
                'category_id' => $this->categoryId($category),
                'website_url' => $url,
                'status' => true,
                // Spread out, to see «most viewed first» in each category.
                'views_count' => random_int(0, 5000),
                'listed_until' => now()->addDays(random_int(5, 60)),
            ]);
            $portal->translations()->createMany([
                ['locale' => 'en', 'name' => $en, 'description' => $enDesc],
                ['locale' => 'ar', 'name' => $ar, 'description' => $arDesc],
            ]);
            if (($logo = $this->logo($en, $colour, $font)) !== null) {
                $portal->addMedia($logo)->usingFileName(Str::uuid().'.png')->toMediaCollection(ChatPortal::LOGO);
            }
        }

        $this->command?->info('20 demo portals listed'.($me ? " (a few owned by user #{$me->getKey()})" : '').'.');
    }

    private function demoUser(): ?User
    {
        $id = DB::table('personal_access_tokens')->where('tokenable_type', 'user')->where('name', 'mobile-app')
            ->orderByDesc('last_used_at')->value('tokenable_id');

        return $id ? User::query()->find($id) : User::query()->latest('id')->first();
    }

    private function categoryId(string $english): ?int
    {
        $id = DB::table('chat_category_translations')->where('locale', 'en')->where('name', $english)->value('chat_category_id');

        return $id ? (int) $id : ChatCategory::query()->value('id');
    }

    /** The brand's initials, white on its colour, 256×256 — a temp PNG (null without GD). */
    private function logo(string $name, string $hex, string $font): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }
        $words = preg_split('/[\s\-]+/', preg_replace('/[^A-Za-z\s\-]/', '', $name)) ?: [$name];
        $initials = strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(array_filter($words), 0, 2))));

        $size = 256;
        $img = imagecreatetruecolor($size, $size);
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        imagefilledrectangle($img, 0, 0, $size, $size, imagecolorallocate($img, $r, $g, $b));
        $white = imagecolorallocate($img, 255, 255, 255);
        if (is_file($font)) {
            $pt = strlen($initials) > 1 ? 96 : 120;
            $box = imagettfbbox($pt, 0, $font, $initials);
            $x = (int) (($size - ($box[2] - $box[0])) / 2 - $box[0]);
            $y = (int) (($size - ($box[1] - $box[7])) / 2 - $box[7]);
            imagettftext($img, $pt, 0, $x, $y, $white, $font, $initials);
        } else {
            imagestring($img, 5, 110, 120, $initials, $white);
        }
        $path = tempnam(sys_get_temp_dir(), 'portal').'.png';
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }
}
