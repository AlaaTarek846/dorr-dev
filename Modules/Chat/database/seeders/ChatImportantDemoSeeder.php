<?php

namespace Modules\Chat\Database\Seeders;

use App\Enums\UserStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Services\ContactService;
use Modules\Chat\Services\ConversationService;
use Modules\Chat\Services\MessageService;
use Modules\User\Models\User;

/**
 * Local demo: lots of unread chats so "المهم في رسايلي" has something to pick (max 10).
 *
 *   php artisan db:seed --class="Modules\\Chat\\Database\\Seeders\\ChatImportantDemoSeeder"
 *
 * Targets the last mobile-app login (Sanctum `mobile-app`), so the sheet on the device
 * actually has unread chats. Re-run is safe (fixed uuids per viewer).
 */
class ChatImportantDemoSeeder extends Seeder
{
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

        $countryId = $viewer->country_id;
        $contacts = app(ContactService::class);
        $conversations = app(ConversationService::class);
        $messages = app(MessageService::class);

        $people = [
            ['سارة علي', '501111001', [
                ['تقدري تحولي الإيجار النهاردة؟ 4500 ريال', true],
                ['المالك بيسأل من الصبح', false],
                ['صباح الخير', false],
            ]],
            ['عمر حسن', '501111002', [
                ['الاجتماع اتنقل لـ 5 العصر، تؤكدي؟', false],
                ['لو مش هتلحقي قولي عشان ألغي القاعة', true],
                ['تمام، أشوفك بكرة', false],
            ]],
            ['لينا محمود', '501111003', [
                ['العقد بينتهي بكرة، وقّعتي النسخة الأخيرة؟', true],
                ['المحامي مستني الرد قبل 6', false],
                ['هههه نسيت أقولك عيد ميلاد سعيد', false],
            ]],
            ['كريم فؤاد', '501111004', [
                ['عامل إيه؟', false],
                ['الجو حر أوي النهارده', false],
                ['شوفت الماتش؟', false],
            ]],
            ['نور الدين', '501111005', [
                ['محتاج رقم البطاقة عشان التحويل البنكي', true],
                ['المبلغ 12,800 جنيه، نفس الحساب؟', false],
                ['ابعت الإيصال لما يوصل', false],
            ]],
            ['هاني يوسف', '501111006', [
                ['الطيارة 9 الصبح، جواز السفر معاك؟', true],
                ['التشيك إن قفل 7:30', false],
                ['وصلت المطار؟', false],
            ]],
            ['منى سالم', '501111007', [
                ['الطفل سخن 39 من بليل، نروح المستشفى؟', true],
                ['الدكتور قال لو زاد عن 39.5 نتحرك', false],
                ['تمام شكراً', false],
            ]],
            ['يوسف إبراهيم', '501111008', [
                ['فاتورة الكهربا آخر مهلة النهاردة، 890 جنيه', false],
                ['حولت ولا لسه؟', true],
                ['ماشي', false],
            ]],
            ['دينا راشد', '501111009', [
                ['العميل رفض العرض، محتاج ردك قبل الساعة 3', true],
                ['لو موافقين نوقع النهاردة', false],
                ['صباح الخير فريق العمل', false],
            ]],
            ['علي خليفة', '501111010', [
                ['فين المفاتيح؟ العربية واقفة قدام البيت', true],
                ['الناس مستنياني', false],
                ['ok', false],
            ]],
            ['عمرو حامد', '501111011', [
                ['تحويل المحفظة فشل، تجرب تاني؟', true],
                ['الرقم المرجعي 88421', false],
                ['تصبح على خير', false],
            ]],
            ['خالد ناصر', '501111012', [
                ['قرار اللجنة: إما ندفع غرامة 15 ألف أو نعترض قبل الجمعة', true],
                ['المستندات في الإيميل', false],
                ['شوفت الرسالة؟', false],
            ]],
            ['هدى كمال', '501111013', [
                ['تقدري تكلمي المدرسة؟ حجزوا اجتماع ولي أمر بكرة 11', false],
                ['لو مش فاضي أنا هروح', false],
                ['حبيبي', false],
            ]],
            ['طارق نبيل', '501111014', [
                ['السيفر وقع، الباسورد بتاع الإنتاج عندك؟ عاجل', true],
                ['العملاء مش عارفين يدخلوا', false],
                ['ping', false],
            ]],
            ['إيمان عادل', '501111015', [
                ['القسط الاستحقاق 28 الشهر، 3200، نفس الفيزا؟', false],
                ['البنك بعت إنذار', true],
                ['يا رب', false],
            ]],
        ];

        $sent = 0;
        foreach ($people as $i => [$name, $local, $lines]) {
            $phone = '+966'.$local;
            $other = User::query()->firstOrCreate(
                ['phone' => $phone],
                [
                    'name' => $name,
                    'country_id' => $countryId,
                    'status' => UserStatus::Active,
                    'phone_verified_at' => now(),
                ]
            );
            if (blank($other->name)) {
                $other->update(['name' => $name]);
            }

            $contacts->add($viewer, $name, $phone, $viewer->country);
            $contacts->add($other, $viewer->name ?: 'أنا', $viewer->phone, $other->country ?? $viewer->country);

            $mine = $conversations->openDirect($viewer, $other);
            $chat = $mine->conversation;
            $other->refresh();

            foreach ($lines as $n => [$body, $urgent]) {
                $messages->send($other, $chat, [
                    'type' => 'text',
                    'body' => $body,
                    'uuid' => sprintf('impdemo-%d-%d-%d', $viewer->id, $other->id, $n),
                    'urgent' => $urgent,
                ]);
                $sent++;
            }
        }

        $this->command?->info("Seeded {$sent} unread messages for {$viewer->phone} (user {$viewer->id}) across ".count($people).' chats.');
    }
}
