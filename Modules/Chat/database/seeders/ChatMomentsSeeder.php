<?php

namespace Modules\Chat\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The starting catalog of occasions (DORR Moments, spec 157–159). Idempotent: an occasion already
 * there (by `key`) is left as the admin edited it. Everything here can be changed from the admin —
 * names, countries, dates, colours, animation, a card picture.
 *
 * `default_on = false`: shown only to people who pick it in their preferences (nothing is
 * inferred about anyone's religion or nationality, spec 168).
 */
class ChatMomentsSeeder extends Seeder
{
    /** Arab countries that mark Mother's Day on 21 March (and Father's Day on 21 June). */
    private const ARAB_MARCH = ['EG', 'SA', 'AE', 'KW', 'QA', 'BH', 'OM', 'JO', 'LB', 'SY', 'IQ', 'PS', 'YE', 'SD', 'LY'];

    /** Where the Prophet's birthday is an occasion. */
    private const MAWLID = ['EG', 'JO', 'MA', 'DZ', 'TN', 'LY', 'SD', 'SY', 'IQ', 'LB', 'PS', 'YE', 'OM', 'KW', 'BH', 'AE', 'QA', 'TR', 'MR'];

    public function run(): void
    {
        if (! Schema::hasTable('chat_moments')) {
            return;
        }

        foreach ($this->moments() as $i => $m) {
            if (DB::table('chat_moments')->where('key', $m['key'])->exists()) {
                continue;
            }

            [$primary, $secondary, $emoji, $animation] = $m['look'];
            $id = DB::table('chat_moments')->insertGetId([
                'key' => $m['key'],
                'kind' => $m['kind'],
                'date_rule' => $m['rule'],
                'month' => $m['month'] ?? null,
                'day' => $m['day'] ?? null,
                'duration_days' => $m['duration'] ?? 1,
                'show_before_days' => $m['before'] ?? 3,
                'show_after_days' => $m['after'] ?? 0,
                'countries' => isset($m['countries']) ? json_encode($m['countries']) : null,
                'default_on' => $m['default_on'] ?? true,
                'theme' => $m['theme'],
                'primary_color' => $primary,
                'secondary_color' => $secondary,
                'emoji' => $emoji,
                'animation' => $animation,
                'status' => true,
                'sort_order' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (['ar' => $m['ar'], 'en' => $m['en']] as $locale => [$name, $greeting]) {
                DB::table('chat_moment_translations')->insert([
                    'chat_moment_id' => $id, 'locale' => $locale, 'name' => $name, 'greeting' => $greeting,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            // Dates the admin keeps for moments with no fixed rule (e.g. Mother's Day in May).
            foreach ($m['dates'] ?? [] as $date) {
                DB::table('chat_moment_dates')->insert([
                    'chat_moment_id' => $id, 'country_id' => null, 'year' => (int) substr($date, 0, 4), 'date' => $date,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function moments(): array
    {
        $national = fn (string $key, string $code, int $month, int $day, string $ar, string $en, string $c1, string $c2) => [
            'key' => $key, 'kind' => 'national', 'rule' => 'gregorian', 'month' => $month, 'day' => $day, 'before' => 3,
            'countries' => [$code], 'theme' => 'national', 'look' => [$c1, $c2, '🎉', 'flags'],
            'ar' => [$ar, 'كل عام والوطن بخير 🎉'], 'en' => [$en, 'Happy national day! 🎉'],
        ];

        return [
            // ------------------------------------------------------------ Islamic (Umm al-Qura)
            ['key' => 'ramadan', 'kind' => 'religious', 'rule' => 'hijri', 'month' => 9, 'day' => 1, 'duration' => 30, 'before' => 5, 'theme' => 'ramadan', 'look' => ['#1E3A8A', '#F59E0B', '🌙', 'lanterns'],
                'ar' => ['رمضان', 'رمضان كريم 🌙 كل عام وأنت بخير'], 'en' => ['Ramadan', 'Ramadan Kareem 🌙']],
            ['key' => 'laylat_al_qadr', 'kind' => 'religious', 'rule' => 'hijri', 'month' => 9, 'day' => 27, 'before' => 1, 'theme' => 'ramadan', 'look' => ['#0F172A', '#FDE68A', '✨', 'stars'],
                'ar' => ['ليلة القدر', 'ليلة مباركة، تقبّل الله منا ومنكم ✨'], 'en' => ['Laylat al-Qadr', 'A blessed night ✨']],
            ['key' => 'eid_al_fitr', 'kind' => 'religious', 'rule' => 'hijri', 'month' => 10, 'day' => 1, 'duration' => 3, 'before' => 3, 'theme' => 'eid', 'look' => ['#0F766E', '#FBBF24', '🎉', 'fireworks'],
                'ar' => ['عيد الفطر', 'عيد فطر سعيد 🎉 كل عام وأنتم بخير'], 'en' => ['Eid al-Fitr', 'Eid Mubarak! 🎉']],
            ['key' => 'day_of_arafah', 'kind' => 'religious', 'rule' => 'hijri', 'month' => 12, 'day' => 9, 'before' => 1, 'theme' => 'hajj', 'look' => ['#065F46', '#D4AF37', '🤲', 'stars'],
                'ar' => ['يوم عرفة', 'يوم عرفة مبارك 🤲'], 'en' => ['Day of Arafah', 'A blessed Day of Arafah 🤲']],
            ['key' => 'eid_al_adha', 'kind' => 'religious', 'rule' => 'hijri', 'month' => 12, 'day' => 10, 'duration' => 4, 'before' => 3, 'theme' => 'eid', 'look' => ['#7C2D12', '#FBBF24', '🐑', 'fireworks'],
                'ar' => ['عيد الأضحى', 'عيد أضحى مبارك 🐑 كل عام وأنتم بخير'], 'en' => ['Eid al-Adha', 'Eid al-Adha Mubarak! 🐑']],
            ['key' => 'islamic_new_year', 'kind' => 'religious', 'rule' => 'hijri', 'month' => 1, 'day' => 1, 'before' => 2, 'theme' => 'hijri_new_year', 'look' => ['#312E81', '#A78BFA', '🌙', 'stars'],
                'ar' => ['رأس السنة الهجرية', 'كل عام هجري وأنت بخير 🌙'], 'en' => ['Islamic New Year', 'Happy Hijri New Year 🌙']],
            ['key' => 'mawlid', 'kind' => 'religious', 'rule' => 'hijri', 'month' => 3, 'day' => 12, 'before' => 2, 'countries' => self::MAWLID, 'theme' => 'mawlid', 'look' => ['#047857', '#34D399', '✨', 'sparkles'],
                'ar' => ['المولد النبوي', 'كل عام وأنتم بخير بمناسبة المولد النبوي ✨'], 'en' => ['Mawlid', 'Blessed Mawlid ✨']],
            ['key' => 'isra_miraj', 'kind' => 'religious', 'rule' => 'hijri', 'month' => 7, 'day' => 27, 'before' => 1, 'theme' => 'hijri_new_year', 'look' => ['#1E1B4B', '#C4B5FD', '🌌', 'stars'],
                'ar' => ['الإسراء والمعراج', 'ذكرى الإسراء والمعراج 🌌'], 'en' => ['Isra and Mi\'raj', 'Blessed Isra and Mi\'raj 🌌']],
            ['key' => 'mid_shaban', 'kind' => 'religious', 'rule' => 'hijri', 'month' => 8, 'day' => 15, 'before' => 1, 'default_on' => false, 'theme' => 'ramadan', 'look' => ['#1E3A8A', '#93C5FD', '🌕', 'stars'],
                'ar' => ['ليلة النصف من شعبان', 'ليلة مباركة 🌕'], 'en' => ['Mid-Sha\'ban', 'A blessed night 🌕']],
            ['key' => 'ashura', 'kind' => 'religious', 'rule' => 'hijri', 'month' => 1, 'day' => 10, 'before' => 1, 'default_on' => false, 'theme' => 'hijri_new_year', 'look' => ['#334155', '#CBD5E1', '🤍', 'stars'],
                'ar' => ['عاشوراء', 'يوم عاشوراء 🤍'], 'en' => ['Ashura', 'Ashura 🤍']],

            // ------------------------------------------------------------ other faiths (picked by those who want them)
            ['key' => 'christmas', 'kind' => 'religious', 'rule' => 'gregorian', 'month' => 12, 'day' => 25, 'before' => 3, 'default_on' => false, 'theme' => 'christmas', 'look' => ['#B91C1C', '#15803D', '🎄', 'snow'],
                'ar' => ['عيد الميلاد المجيد', 'ميلاد مجيد 🎄'], 'en' => ['Christmas', 'Merry Christmas 🎄']],
            ['key' => 'coptic_christmas', 'kind' => 'religious', 'rule' => 'gregorian', 'month' => 1, 'day' => 7, 'before' => 2, 'default_on' => false, 'countries' => ['EG'], 'theme' => 'christmas', 'look' => ['#1E40AF', '#FBBF24', '⭐', 'snow'],
                'ar' => ['عيد الميلاد المجيد (7 يناير)', 'عيد ميلاد مجيد ⭐'], 'en' => ['Orthodox Christmas', 'Merry Christmas ⭐']],
            ['key' => 'easter', 'kind' => 'religious', 'rule' => 'manual', 'before' => 2, 'default_on' => false, 'theme' => 'spring', 'look' => ['#7C3AED', '#FDE047', '🌸', 'petals'],
                'dates' => ['2026-04-05', '2027-03-28', '2028-04-16'],
                'ar' => ['عيد القيامة', 'عيد قيامة مجيد 🌸'], 'en' => ['Easter', 'Happy Easter 🌸']],
            ['key' => 'sham_el_nessim', 'kind' => 'cultural', 'rule' => 'manual', 'before' => 2, 'countries' => ['EG'], 'theme' => 'spring', 'look' => ['#16A34A', '#FDE047', '🌼', 'petals'],
                'dates' => ['2026-04-13', '2027-04-26', '2028-04-17'],
                'ar' => ['شم النسيم', 'شم نسيم سعيد 🌼'], 'en' => ['Sham el-Nessim', 'Happy Sham el-Nessim 🌼']],

            // ------------------------------------------------------------ international & social
            ['key' => 'new_year', 'kind' => 'international', 'rule' => 'gregorian', 'month' => 1, 'day' => 1, 'before' => 2, 'theme' => 'new_year', 'look' => ['#111827', '#FBBF24', '🎆', 'fireworks'],
                'ar' => ['رأس السنة الميلادية', 'سنة سعيدة 🎆 كل عام وأنت بخير'], 'en' => ['New Year', 'Happy New Year 🎆']],
            ['key' => 'valentines', 'kind' => 'social', 'rule' => 'gregorian', 'month' => 2, 'day' => 14, 'before' => 2, 'default_on' => false, 'theme' => 'love', 'look' => ['#E11D48', '#FDA4AF', '❤️', 'hearts'],
                'ar' => ['عيد الحب', 'كل سنة وأنت حبيبي ❤️'], 'en' => ['Valentine\'s Day', 'Happy Valentine\'s ❤️']],
            ['key' => 'womens_day', 'kind' => 'international', 'rule' => 'gregorian', 'month' => 3, 'day' => 8, 'before' => 1, 'theme' => 'women', 'look' => ['#9333EA', '#F0ABFC', '🌷', 'petals'],
                'ar' => ['يوم المرأة العالمي', 'كل عام وكل امرأة بخير 🌷'], 'en' => ['International Women\'s Day', 'Happy Women\'s Day 🌷']],
            ['key' => 'mothers_day', 'kind' => 'social', 'rule' => 'gregorian', 'month' => 3, 'day' => 21, 'before' => 3, 'countries' => self::ARAB_MARCH, 'theme' => 'mother', 'look' => ['#DB2777', '#FBCFE8', '💐', 'petals'],
                'ar' => ['عيد الأم', 'كل سنة وإنتي طيبة يا أحلى أم 💐'], 'en' => ['Mother\'s Day', 'Happy Mother\'s Day 💐']],
            ['key' => 'mothers_day_may', 'kind' => 'social', 'rule' => 'manual', 'before' => 3, 'countries' => ['TR', 'US', 'CA', 'AU', 'DE', 'IT', 'MA'], 'theme' => 'mother', 'look' => ['#DB2777', '#FBCFE8', '💐', 'petals'],
                'dates' => ['2026-05-10', '2027-05-09', '2028-05-14'],
                'ar' => ['عيد الأم', 'كل سنة وإنتي طيبة يا أحلى أم 💐'], 'en' => ['Mother\'s Day', 'Happy Mother\'s Day 💐']],
            ['key' => 'labour_day', 'kind' => 'international', 'rule' => 'gregorian', 'month' => 5, 'day' => 1, 'before' => 1, 'theme' => 'work', 'look' => ['#475569', '#F59E0B', '🛠️', 'confetti'],
                'ar' => ['عيد العمال', 'كل عام وكل العاملين بخير 🛠️'], 'en' => ['Labour Day', 'Happy Labour Day 🛠️']],
            ['key' => 'fathers_day', 'kind' => 'social', 'rule' => 'gregorian', 'month' => 6, 'day' => 21, 'before' => 3, 'countries' => self::ARAB_MARCH, 'theme' => 'father', 'look' => ['#1D4ED8', '#93C5FD', '👔', 'stars'],
                'ar' => ['عيد الأب', 'كل سنة وإنت طيب يا أحسن أب 👔'], 'en' => ['Father\'s Day', 'Happy Father\'s Day 👔']],
            ['key' => 'friendship_day', 'kind' => 'international', 'rule' => 'gregorian', 'month' => 7, 'day' => 30, 'before' => 1, 'theme' => 'friendship', 'look' => ['#F97316', '#FDE047', '🤝', 'confetti'],
                'ar' => ['يوم الصداقة العالمي', 'لأحلى صاحب 🤝'], 'en' => ['International Friendship Day', 'Happy Friendship Day 🤝']],
            ['key' => 'youth_day', 'kind' => 'international', 'rule' => 'gregorian', 'month' => 8, 'day' => 12, 'before' => 1, 'default_on' => false, 'theme' => 'friendship', 'look' => ['#0EA5E9', '#A3E635', '⚡', 'confetti'],
                'ar' => ['اليوم الدولي للشباب', 'كل عام والشباب بخير ⚡'], 'en' => ['International Youth Day', 'Happy Youth Day ⚡']],
            ['key' => 'teachers_day', 'kind' => 'international', 'rule' => 'gregorian', 'month' => 10, 'day' => 5, 'before' => 1, 'theme' => 'teacher', 'look' => ['#0369A1', '#FDE68A', '📚', 'stars'],
                'ar' => ['يوم المعلم العالمي', 'شكرًا لكل معلم 📚'], 'en' => ['World Teachers\' Day', 'Thank you, teacher 📚']],
            ['key' => 'childrens_day', 'kind' => 'international', 'rule' => 'gregorian', 'month' => 11, 'day' => 20, 'before' => 1, 'theme' => 'children', 'look' => ['#F59E0B', '#60A5FA', '🎈', 'balloons'],
                'ar' => ['يوم الطفل العالمي', 'لكل طفل ضحكة 🎈'], 'en' => ['World Children\'s Day', 'Happy Children\'s Day 🎈']],
            ['key' => 'arabic_language_day', 'kind' => 'cultural', 'rule' => 'gregorian', 'month' => 12, 'day' => 18, 'before' => 1, 'default_on' => false, 'theme' => 'culture', 'look' => ['#92400E', '#FCD34D', '📜', 'sparkles'],
                'ar' => ['اليوم العالمي للغة العربية', 'لغة الضاد 📜'], 'en' => ['World Arabic Language Day', 'Arabic Language Day 📜']],
            ['key' => 'kindness_day', 'kind' => 'international', 'rule' => 'gregorian', 'month' => 11, 'day' => 13, 'before' => 1, 'default_on' => false, 'theme' => 'friendship', 'look' => ['#10B981', '#FDE68A', '💛', 'hearts'],
                'ar' => ['اليوم العالمي للطيبة', 'كلمة طيبة صدقة 💛'], 'en' => ['World Kindness Day', 'Be kind 💛']],

            // ------------------------------------------------------------ national days
            $national('national_day_sa', 'SA', 9, 23, 'اليوم الوطني السعودي', 'Saudi National Day', '#006C35', '#FFFFFF'),
            $national('founding_day_sa', 'SA', 2, 22, 'يوم التأسيس', 'Saudi Founding Day', '#7A5230', '#006C35'),
            $national('flag_day_sa', 'SA', 3, 11, 'يوم العلم', 'Saudi Flag Day', '#006C35', '#FFFFFF'),
            $national('revolution_day_eg', 'EG', 7, 23, 'عيد ثورة 23 يوليو', 'Egypt Revolution Day', '#CE1126', '#000000'),
            $national('october_victory_eg', 'EG', 10, 6, 'عيد نصر أكتوبر', 'October Victory Day', '#CE1126', '#C09300'),
            $national('sinai_day_eg', 'EG', 4, 25, 'عيد تحرير سيناء', 'Sinai Liberation Day', '#CE1126', '#000000'),
            $national('national_day_ae', 'AE', 12, 2, 'عيد الاتحاد الإماراتي', 'UAE National Day', '#00732F', '#FF0000'),
            $national('national_day_kw', 'KW', 2, 25, 'العيد الوطني الكويتي', 'Kuwait National Day', '#007A3D', '#CE1126'),
            $national('liberation_day_kw', 'KW', 2, 26, 'عيد التحرير الكويتي', 'Kuwait Liberation Day', '#007A3D', '#000000'),
            $national('national_day_qa', 'QA', 12, 18, 'اليوم الوطني القطري', 'Qatar National Day', '#8A1538', '#FFFFFF'),
            $national('national_day_bh', 'BH', 12, 16, 'العيد الوطني البحريني', 'Bahrain National Day', '#CE1126', '#FFFFFF'),
            $national('national_day_om', 'OM', 11, 20, 'العيد الوطني العُماني', 'Oman National Day', '#DB161B', '#008000'),
            $national('independence_day_jo', 'JO', 5, 25, 'عيد الاستقلال الأردني', 'Jordan Independence Day', '#007A3D', '#CE1126'),
            $national('throne_day_ma', 'MA', 7, 30, 'عيد العرش المغربي', 'Morocco Throne Day', '#C1272D', '#006233'),
            $national('independence_day_ma', 'MA', 11, 18, 'عيد الاستقلال المغربي', 'Morocco Independence Day', '#C1272D', '#006233'),
            $national('revolution_day_dz', 'DZ', 11, 1, 'عيد الثورة الجزائرية', 'Algeria Revolution Day', '#006233', '#D21034'),
            $national('independence_day_dz', 'DZ', 7, 5, 'عيد الاستقلال الجزائري', 'Algeria Independence Day', '#006233', '#D21034'),
            $national('independence_day_tn', 'TN', 3, 20, 'عيد الاستقلال التونسي', 'Tunisia Independence Day', '#E70013', '#FFFFFF'),
            $national('independence_day_lb', 'LB', 11, 22, 'عيد الاستقلال اللبناني', 'Lebanon Independence Day', '#ED1C24', '#00A651'),
            $national('national_day_iq', 'IQ', 10, 3, 'اليوم الوطني العراقي', 'Iraq National Day', '#CE1126', '#007A3D'),
            $national('evacuation_day_sy', 'SY', 4, 17, 'عيد الجلاء السوري', 'Syria Evacuation Day', '#CE1126', '#007A3D'),
            $national('independence_day_ps', 'PS', 11, 15, 'ذكرى إعلان الاستقلال الفلسطيني', 'Palestine Independence Day', '#007A3D', '#CE1126'),
            $national('independence_day_sd', 'SD', 1, 1, 'عيد الاستقلال السوداني', 'Sudan Independence Day', '#D21034', '#007229'),
            $national('independence_day_ly', 'LY', 12, 24, 'عيد الاستقلال الليبي', 'Libya Independence Day', '#E70013', '#239E46'),
            $national('unity_day_ye', 'YE', 5, 22, 'العيد الوطني اليمني', 'Yemen Unity Day', '#CE1126', '#000000'),
            $national('independence_day_mr', 'MR', 11, 28, 'عيد الاستقلال الموريتاني', 'Mauritania Independence Day', '#00A95C', '#FFD700'),
            $national('republic_day_tr', 'TR', 10, 29, 'عيد الجمهورية التركي', 'Turkey Republic Day', '#E30A17', '#FFFFFF'),
            $national('independence_day_us', 'US', 7, 4, 'عيد الاستقلال الأمريكي', 'US Independence Day', '#B22234', '#3C3B6E'),
        ];
    }
}
