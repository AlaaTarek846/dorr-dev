<?php

namespace Modules\User\Database\Seeders;

use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Modules\Admin\Models\Admin;
use Modules\User\Enums\SupportTicketStatus;
use Modules\User\Models\SupportMessage;
use Modules\User\Models\SupportSetting;
use Modules\User\Models\SupportTicket;
use Modules\User\Models\SupportTicketActivity;
use Modules\User\Models\User;

/**
 * Demo tickets for the demo user, one for each situation the dashboard and the app show:
 * an acknowledgement waiting for an agent, an away note, an FAQ answer on a closed ticket,
 * an agent's answer on a resolved ticket, and a customer who asked for a person.
 * A title that already exists for the user is skipped, so re-running adds nothing.
 */
class SupportTicketDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('email', 'user@example.com')->first();

        if (! $user) {
            return;
        }

        $agent = Admin::query()->where('email', 'admin@admin.com')->first();

        foreach ($this->tickets() as $ticket) {
            if (SupportTicket::query()->where('user_id', $user->id)->where('title', $ticket['title'])->exists()) {
                continue;
            }

            $this->create($user, $agent, $ticket);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function create(User $user, ?Admin $agent, array $data): void
    {
        $start = now()->subMinutes($data['minutes_ago']);
        $locale = 'ar';
        $agentReplies = (bool) ($data['agent_replies'] ?? false);

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'admin_id' => $agentReplies ? $agent?->id : null,
            'title' => $data['title'],
            'body' => $data['body'],
            'status' => SupportTicketStatus::Opened,
            'last_message_at' => $start,
            'auto_reply_stopped_at' => ($data['wants_agent'] ?? false) ? $start->copy()->addMinutes(8) : null,
        ]);

        SupportTicketActivity::query()->create([
            'support_ticket_id' => $ticket->id,
            'actor' => 'user',
            'status' => SupportTicketStatus::Opened,
        ]);

        $this->message($ticket, $user->id, SupportMessage::SENDER_USER, $data['body'], $start);

        $settings = SupportSetting::current();
        $at = $start->copy();

        foreach ($data['auto'] as $kind) {
            $at = $at->copy()->addSeconds(5);
            $text = match ($kind) {
                SupportSetting::KIND_ACK => $settings->textFor(SupportSetting::KIND_ACK, $locale, ['id' => $ticket->id]),
                SupportSetting::KIND_AWAY => $settings->textFor(SupportSetting::KIND_AWAY, $locale, ['id' => $ticket->id, 'hours' => $settings->hoursText($locale)]),
                default => $data['faq_answer'],
            };

            $this->message($ticket, $user->id, SupportMessage::SENDER_SYSTEM, $text, $at, autoKind: $kind);
        }

        if ($agentReplies) {
            $at = $at->copy()->addMinutes(20);
            $this->message($ticket, $user->id, SupportMessage::SENDER_SUPPORT, $data['agent_reply'], $at, adminId: $agent?->id);
        }

        if ($data['status'] !== SupportTicketStatus::Opened) {
            $at = $at->copy()->addMinutes(30);
            $byAgent = $data['status'] === SupportTicketStatus::Resolved;

            $ticket->forceFill(['status' => $data['status']])->save();
            SupportTicketActivity::query()->create([
                'support_ticket_id' => $ticket->id,
                'admin_id' => $byAgent ? $agent?->id : null,
                'actor' => $byAgent ? 'support' : 'user',
                'status' => $data['status'],
            ]);
        }

        $ticket->forceFill(['last_message_at' => $at])->save();
    }

    protected function message(SupportTicket $ticket, ?int $userId, string $sender, string $body, CarbonInterface $at, ?string $autoKind = null, ?int $adminId = null): void
    {
        $message = SupportMessage::query()->create([
            'user_id' => $userId,
            'admin_id' => $adminId,
            'support_ticket_id' => $ticket->id,
            'sender' => $sender,
            'body' => $body,
            'is_auto' => $autoKind !== null,
            'auto_kind' => $autoKind,
        ]);

        $message->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function tickets(): array
    {
        return [
            [
                'title' => 'لم يصلني رمز التحقق',
                'body' => 'طلبت رمز التحقق أكثر من مرة ولم يصلني أي رسالة على جوالي.',
                'minutes_ago' => 25,
                'auto' => [SupportSetting::KIND_ACK],
                'status' => SupportTicketStatus::Opened,
            ],
            [
                'title' => 'استفسار خارج أوقات العمل',
                'body' => 'السلام عليكم، أريد معرفة طريقة تغيير رقم جوالي في التطبيق.',
                'minutes_ago' => 600,
                'auto' => [SupportSetting::KIND_AWAY],
                'status' => SupportTicketStatus::Opened,
            ],
            [
                'title' => 'كيف أغلق تذكرة؟',
                'body' => 'كيف يمكنني إغلاق تذكرة دعم فتحتها سابقاً؟',
                'minutes_ago' => 1500,
                'auto' => [SupportSetting::KIND_ACK, SupportSetting::KIND_FAQ],
                'faq_answer' => 'افتح التذكرة واضغط إغلاق عند حل مشكلتك. ويمكنك إعادة فتح تذكرة مغلقة أو محلولة بزر إعادة الفتح إن كنت ما زلت تحتاج مساعدة.',
                'status' => SupportTicketStatus::Closed,
            ],
            [
                'title' => 'تأخر وصول المبلغ إلى المحفظة',
                'body' => 'شحنت محفظتي منذ يومين ولم يظهر الرصيد حتى الآن.',
                'minutes_ago' => 2900,
                'auto' => [SupportSetting::KIND_ACK],
                'agent_replies' => true,
                'agent_reply' => 'مرحباً بك، راجعنا عملية الشحن وتبيّن أنها اكتملت، وقد ظهر الرصيد الآن في محفظتك. نعتذر عن التأخير.',
                'status' => SupportTicketStatus::Resolved,
            ],
            [
                'title' => 'أحتاج التحدث مع موظف',
                'body' => 'كيف أحذف حسابي؟',
                'minutes_ago' => 180,
                'auto' => [SupportSetting::KIND_ACK, SupportSetting::KIND_FAQ],
                'faq_answer' => 'يمكنك طلب حذف حسابك من الملف الشخصي ثم الإعدادات. ولأن الحذف لا يمكن التراجع عنه، تأكد من سحب رصيدك أولاً.',
                'wants_agent' => true,
                'status' => SupportTicketStatus::Opened,
            ],
        ];
    }
}
