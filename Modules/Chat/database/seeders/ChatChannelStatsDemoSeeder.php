<?php

namespace Modules\Chat\Database\Seeders;

use App\Enums\UserStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\ConversationType;
use Modules\Chat\Models\ChatGroup;
use Modules\Chat\Models\ChatMessageReceipt;
use Modules\Chat\Services\ChannelService;
use Modules\Chat\Services\MessageService;
use Modules\User\Models\User;

/**
 * Local demo: a channel I own with followers, people who joined / left, and posts with views spread over
 * the last 90 days — so «إحصائيات القناة» shows something in every period (7 / 30 / 90 days).
 *
 *   php artisan db:seed --class="Modules\\Chat\\Database\\Seeders\\ChatChannelStatsDemoSeeder"
 *
 * Targets the last mobile-app login. Re-run skips when the demo channel already exists.
 */
class ChatChannelStatsDemoSeeder extends Seeder
{
    private const NAME = 'قناة الإحصائيات التجريبية';

    /** Post texts; each one gets a different share of followers who saw it. */
    private const POSTS = [
        'أهلاً بكم في القناة 👋 هنا كل جديد أول بأول.',
        'عرض نهاية الأسبوع: خصم 20٪ على كل الطلبات حتى الأحد.',
        'تذكير: مواعيد العمل في رمضان من 8 مساءً حتى 2 صباحًا.',
        'استطلاع سريع: ما المنتج اللي تحبوا نرجّعه؟ ردّوا في الخاص.',
        'وصلت الدفعة الجديدة 📦 الكميات محدودة.',
        'شكرًا لكم! وصلنا 1000 طلب هذا الشهر 🎉',
        'نصيحة اليوم: احفظ رقمنا علشان توصلك العروض قبل الكل.',
        'تحديث: التوصيل المجاني بقى من 150 ريال بدل 200.',
        'صور من الفعالية امبارح ✨',
        'سؤال وجواب: إزاي تتابع طلبك من التطبيق خطوة بخطوة.',
        'تنبيه: الموقع هيكون تحت الصيانة الليلة من 1 لـ 3 الفجر.',
        'مسابقة الأسبوع: أول 10 يشاركوا القناة لهم هدية 🎁',
        'وصل حديثًا: المقاسات الكبيرة متاحة الآن.',
        'خصم خاص لمتابعي القناة فقط: كود DORR10',
        'قصة عميل: إزاي وفّرنا له التوصيل في نفس اليوم.',
        'المنتجات الأكثر مبيعًا هذا الأسبوع 🔥',
        'تذكير بآخر يوم للعرض الليلة الساعة 12.',
        'فتحنا فرع جديد في جدة 📍',
        'شاركونا رأيكم في الخدمة، رأيكم يهمّنا.',
        'إجازة رسمية يوم الخميس، والطلبات تتسلّم السبت.',
        'دليل المقاسات الجديد متاح في التطبيق.',
        'شكر خاص لكل اللي تابع القناة من أول يوم ❤️',
        'جديد: الدفع عند الاستلام متاح لكل المدن.',
        'ملخص الشهر: أكتر 5 أسئلة وصلتنا وإجاباتها.',
    ];

    public function run(): void
    {
        $tokenUserId = DB::table('personal_access_tokens')
            ->where('tokenable_type', 'user')
            ->where('name', 'mobile-app')
            ->orderByDesc('last_used_at')
            ->value('tokenable_id');
        $viewer = $tokenUserId ? User::query()->find($tokenUserId) : null;
        $viewer ??= User::query()->where('phone', '+201113667448')->first()
            ?? User::query()->where('phone', '+201277738694')->first();
        if ($viewer === null) {
            $this->command?->error('No viewer user (last mobile token / +201113667448).');

            return;
        }

        $exists = ChatGroup::query()->where('name', self::NAME)
            ->whereHas('conversation', fn ($q) => $q->where('type', ConversationType::Channel->value)
                ->where('created_by_id', $viewer->getKey()))
            ->exists();
        if ($exists) {
            $this->command?->warn('«'.self::NAME."» already exists for {$viewer->phone}; nothing added.");

            return;
        }

        mt_srand(94);
        $channels = app(ChannelService::class);
        $messages = app(MessageService::class);

        $channel = $channels->create($viewer, self::NAME, 'قناة تجريبية لعرض شاشة الإحصائيات: متابعين، منشورات ومشاهدات.', null, true)->conversation;

        // Followers who stay, by when they joined: before the 90-day window, then inside 90 / 30 / 7 days.
        $joinedDaysAgo = array_merge(
            $this->spread(12, 100, 200),
            $this->spread(10, 31, 89),
            $this->spread(10, 8, 29),
            $this->spread(8, 0, 6),
        );
        $followers = [];
        foreach ($joinedDaysAgo as $i => $days) {
            $person = $this->person(sprintf('+966501445%03d', $i + 1), 'متابع '.($i + 1), $viewer->country_id);
            $row = $channels->follow($person, $channel);
            $row->forceFill(['joined_at' => now()->subDays($days)->subHours(mt_rand(0, 20))])->save();
            $followers[] = $row;
        }

        // People who followed long ago and left: 2 in the last week, 2 in the month, 2 in the 90 days.
        foreach ([2, 5, 12, 20, 45, 70] as $k => $leftDaysAgo) {
            $person = $this->person(sprintf('+966501446%03d', $k + 1), 'متابع سابق '.($k + 1), $viewer->country_id);
            $row = $channels->follow($person, $channel);
            $row->forceFill([
                'joined_at' => now()->subDays(150),
                'left_at' => now()->subDays($leftDaysAgo)->subHours(mt_rand(0, 20)),
            ])->save();
        }

        // Posts over the last 85 days (newest last), each seen by a different share of the followers.
        $count = count(self::POSTS);
        foreach (self::POSTS as $n => $body) {
            $at = now()->subDays((int) round(85 * ($count - 1 - $n) / ($count - 1)))->subHours(mt_rand(1, 10));
            $post = $messages->send($viewer, $channel, ['type' => 'text', 'body' => $body, 'uuid' => (string) Str::uuid()]);
            $post->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

            $share = mt_rand(25, 95) / 100;
            foreach ($followers as $follower) {
                if (mt_rand(1, 100) / 100 > $share) {
                    continue;
                }
                $seen = $at->copy()->addMinutes(mt_rand(1, 600));
                ChatMessageReceipt::query()->updateOrCreate(
                    ['message_id' => $post->id, 'participant_id' => $follower->id],
                    ['delivered_at' => $at->copy()->addMinute(), 'read_at' => $seen],
                );
            }
        }

        $this->command?->info('«'.self::NAME."» for {$viewer->phone}: ".count($followers).' followers, 6 left, '.$count.' posts with views.');
    }

    /** @return list<int> `$n` day counts spread between `$from` and `$to`. */
    private function spread(int $n, int $from, int $to): array
    {
        return array_map(fn () => mt_rand($from, $to), range(1, $n));
    }

    private function person(string $phone, string $name, ?int $countryId): User
    {
        return User::query()->firstOrCreate(
            ['phone' => $phone],
            [
                'name' => $name,
                'country_id' => $countryId,
                'status' => UserStatus::Active,
                'phone_verified_at' => now(),
            ],
        );
    }
}
