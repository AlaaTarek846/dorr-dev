<?php

namespace Modules\Chat\Database\Seeders;

use App\Enums\UserStatus;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\ContactService;
use Modules\Chat\Services\ConversationService;
use Modules\Chat\Services\MessageService;
use Modules\User\Models\User;

/**
 * Local demo: files in «إدارة المساحة» (large, forwarded, all, per chat).
 *
 *   php artisan db:seed --class="Modules\\Chat\\Database\\Seeders\\ChatStorageDemoSeeder"
 *
 * Targets the last mobile-app login. Tiny real files; `media.size` is set so large ones
 * show as > 5 MB without writing megabytes to disk. Re-run skips the same uuids.
 */
class ChatStorageDemoSeeder extends Seeder
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
            ['سارة علي', '501222001'],
            ['عمر حسن', '501222002'],
            ['لينا محمود', '501222003'],
        ];
        $chats = [];
        foreach ($people as [$name, $local]) {
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
            $other->refresh();
            $chats[] = ['other' => $other, 'chat' => $mine->conversation];
        }

        [$sara, $omar, $lina] = $chats;
        $made = 0;
        $made += $this->file($messages, $sara, $viewer, fromOther: true, type: 'image', name: 'العقد.jpg', bytes: 6_800_000, forwarded: false, days: 1, n: 1);
        $made += $this->file($messages, $sara, $viewer, fromOther: false, type: 'document', name: 'فاتورة.pdf', bytes: 420_000, forwarded: true, days: 2, n: 2);
        $made += $this->file($messages, $sara, $viewer, fromOther: true, type: 'video', name: 'المكتب.mp4', bytes: 12_400_000, forwarded: false, days: 3, n: 3);
        $made += $this->file($messages, $sara, $viewer, fromOther: false, type: 'image', name: 'الخريطة.jpg', bytes: 1_150_000, forwarded: false, days: 4, n: 4);
        $made += $this->file($messages, $omar, $viewer, fromOther: true, type: 'document', name: 'العرض.pptx', bytes: 5_200_000, forwarded: true, days: 1, n: 1);
        $made += $this->file($messages, $omar, $viewer, fromOther: false, type: 'image', name: 'اللوجو.png', bytes: 280_000, forwarded: false, days: 5, n: 2);
        $made += $this->file($messages, $omar, $viewer, fromOther: true, type: 'document', name: 'الكاتالوج.pdf', bytes: 8_100_000, forwarded: false, days: 6, n: 3);
        $made += $this->file($messages, $omar, $viewer, fromOther: false, type: 'video', name: 'الاجتماع.mp4', bytes: 18_600_000, forwarded: true, days: 7, n: 4);
        $made += $this->file($messages, $lina, $viewer, fromOther: true, type: 'image', name: 'الهوية.jpg', bytes: 95_000, forwarded: false, days: 1, n: 1);
        $made += $this->file($messages, $lina, $viewer, fromOther: false, type: 'document', name: 'التقرير.docx', bytes: 2_400_000, forwarded: true, days: 2, n: 2);
        $made += $this->file($messages, $lina, $viewer, fromOther: true, type: 'image', name: 'الموقع.jpg', bytes: 7_300_000, forwarded: false, days: 8, n: 3);
        $made += $this->file($messages, $lina, $viewer, fromOther: false, type: 'document', name: 'ملاحظات.pdf', bytes: 64_000, forwarded: false, days: 9, n: 4);
        for ($i = 1; $i <= 10; $i++) {
            $made += $this->file($messages, $sara, $viewer, fromOther: $i % 2 === 0, type: 'document', name: "مرفق تجريبي {$i}.pdf", bytes: 80_000 + ($i * 12_000), forwarded: $i % 3 === 0, days: 10 + $i, n: 10 + $i);
        }

        $this->command?->info("Seeded {$made} storage files for {$viewer->phone} (user {$viewer->id}).");
    }

    /**
     * @param  array{other: User, chat: \Modules\Chat\Models\ChatConversation}  $pair
     */
    private function file(
        MessageService $messages,
        array $pair,
        User $viewer,
        bool $fromOther,
        string $type,
        string $name,
        int $bytes,
        bool $forwarded,
        int $days,
        int $n,
    ): int {
        $uuid = sprintf('stodemo-%d-%d-%d', $viewer->id, $pair['other']->id, $n);
        if (ChatMessage::query()->where('uuid', $uuid)->exists()) {
            return 0;
        }

        $upload = match ($type) {
            'image' => UploadedFile::fake()->image($name, 80, 80),
            'video' => UploadedFile::fake()->create($name, 12, 'video/mp4'),
            default => UploadedFile::fake()->create($name, 12, 'application/pdf'),
        };
        $author = $fromOther ? $pair['other'] : $viewer;
        $message = $messages->send($author, $pair['chat'], [
            'type' => $type,
            'uuid' => $uuid,
            'is_forwarded' => $forwarded,
        ], [$upload]);

        $media = $message->getFirstMedia(ChatMessage::ATTACHMENTS);
        if ($media !== null) {
            $media->size = $bytes;
            $media->file_name = $name;
            $media->name = pathinfo($name, PATHINFO_FILENAME);
            $media->save();
        }
        $message->forceFill([
            'created_at' => now()->subDays($days)->subHours($n),
            'is_forwarded' => $forwarded,
        ])->save();

        return 1;
    }
}
