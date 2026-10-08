<?php

namespace Modules\User\Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * Help answers about support itself. They are general (no service) and published, so the AI can
 * rewrite them for a customer in an automatic reply. Matched on the English question; one that exists is kept.
 */
class SupportHelpFaqSeeder extends Seeder
{
    public function run(): void
    {
        $order = (int) Faq::query()->max('sort_order');

        foreach ($this->rows() as $row) {
            $exists = Faq::query()->whereHas('translations', fn ($query) => $query
                ->where('locale', 'en')
                ->where('question', $row['question']['en']))->exists();

            if ($exists) {
                continue;
            }

            $faq = Faq::query()->create(['service_id' => null, 'status' => true, 'sort_order' => ++$order]);

            foreach (['en', 'ar'] as $locale) {
                $faq->translations()->updateOrCreate(
                    ['locale' => $locale],
                    ['question' => $row['question'][$locale], 'answer' => $row['answer'][$locale]],
                );
            }
        }
    }

    /**
     * @return list<array{question: array{en: string, ar: string}, answer: array{en: string, ar: string}}>
     */
    protected function rows(): array
    {
        return [
            [
                'question' => [
                    'en' => 'How do I open a support ticket?',
                    'ar' => 'كيف أفتح تذكرة دعم؟',
                ],
                'answer' => [
                    'en' => 'Open Profile, choose Help & support, then tap New ticket. Write a title and the details, '
                        .'add a photo if it helps, and send. You can follow the conversation from the same screen.',
                    'ar' => 'افتح الملف الشخصي، ثم اختر المساعدة والدعم، ثم اضغط تذكرة جديدة. اكتب عنواناً وتفاصيل المشكلة، '
                        .'وأرفق صورة إن لزم، ثم أرسل. يمكنك متابعة المحادثة من نفس الشاشة.',
                ],
            ],
            [
                'question' => [
                    'en' => 'What are the support working hours?',
                    'ar' => 'ما هي ساعات عمل الدعم؟',
                ],
                'answer' => [
                    'en' => 'Our team answers from Sunday to Thursday, 09:00 to 18:00 (Riyadh time). '
                        .'You can write at any time: your ticket is kept and answered as soon as the team is back.',
                    'ar' => 'يرد فريقنا من الأحد إلى الخميس، من 09:00 إلى 18:00 بتوقيت الرياض. '
                        .'يمكنك الكتابة في أي وقت، وستُحفظ تذكرتك ويُرد عليها فور عودة الفريق.',
                ],
            ],
            [
                'question' => [
                    'en' => 'How long does it take to get a reply?',
                    'ar' => 'كم يستغرق الرد على تذكرتي؟',
                ],
                'answer' => [
                    'en' => 'We usually reply within a few hours during working hours. You will get a notification as soon as an agent answers.',
                    'ar' => 'نرد عادةً خلال ساعات قليلة في أوقات العمل، وسيصلك إشعار فور أن يجيب أحد الموظفين.',
                ],
            ],
            [
                'question' => [
                    'en' => 'How do I close or reopen a ticket?',
                    'ar' => 'كيف أغلق تذكرة أو أعيد فتحها؟',
                ],
                'answer' => [
                    'en' => 'Open the ticket and tap Close when your problem is solved. A closed or resolved ticket can be reopened '
                        .'with the Reopen button if you still need help.',
                    'ar' => 'افتح التذكرة واضغط إغلاق عند حل مشكلتك. ويمكنك إعادة فتح تذكرة مغلقة أو محلولة '
                        .'بزر إعادة الفتح إن كنت ما زلت تحتاج مساعدة.',
                ],
            ],
            [
                'question' => [
                    'en' => 'What is the automatic reply I received?',
                    'ar' => 'ما هو الرد الآلي الذي وصلني؟',
                ],
                'answer' => [
                    'en' => 'Automatic replies confirm that we received your ticket, tell you our working hours, or answer common questions. '
                        .'They are marked "Automatic reply". If it did not solve your problem, tap "I still need a person" and an agent will take over.',
                    'ar' => 'الردود الآلية تؤكد استلام تذكرتك أو تخبرك بأوقات عملنا أو تجيب عن الأسئلة الشائعة، وتحمل علامة "رد آلي". '
                        .'وإن لم تحل مشكلتك فاضغط "ما زلت أحتاج موظفاً" وسيتولى الأمر أحد الموظفين.',
                ],
            ],
        ];
    }
}
