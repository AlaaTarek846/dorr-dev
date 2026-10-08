<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\User\Models\SupportQuickReply;

/**
 * Ready-made answers agents drop into a ticket by typing "/" and a shortcut, in Arabic and English.
 * Matched on the shortcut; one the admin already has is left as it is.
 */
class SupportQuickReplySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->rows() as $index => $row) {
            $reply = SupportQuickReply::query()->firstOrCreate(
                ['shortcut' => $row['shortcut']],
                ['sort_order' => $index + 1, 'status' => true],
            );

            // Only a language the reply does not have yet is added: what the admin wrote stays.
            foreach (['ar', 'en'] as $locale) {
                $reply->translations()->firstOrCreate(
                    ['locale' => $locale],
                    ['title' => $row['title'][$locale], 'body' => $row['body'][$locale]],
                );
            }
        }
    }

    /**
     * @return list<array{shortcut: string, title: array{ar: string, en: string}, body: array{ar: string, en: string}}>
     */
    protected function rows(): array
    {
        return [
            [
                'shortcut' => 'hello',
                'title' => ['ar' => 'ترحيب', 'en' => 'Greeting'],
                'body' => [
                    'ar' => 'أهلاً بك في دعم دور، يسعدنا مساعدتك. كيف يمكننا خدمتك؟',
                    'en' => 'Welcome to Dorr support, we are happy to help. How can we assist you?',
                ],
            ],
            [
                'shortcut' => 'details',
                'title' => ['ar' => 'طلب تفاصيل', 'en' => 'Ask for details'],
                'body' => [
                    'ar' => 'لنتمكن من مساعدتك بأسرع وقت، من فضلك زوّدنا بتفاصيل أكثر عن المشكلة، وأرفق صورة للشاشة إن أمكن.',
                    'en' => 'So we can help you as quickly as possible, please send us more details about the problem and attach a screenshot if you can.',
                ],
            ],
            [
                'shortcut' => 'checking',
                'title' => ['ar' => 'جارٍ المراجعة', 'en' => 'Looking into it'],
                'body' => [
                    'ar' => 'نراجع طلبك الآن ونعود إليك خلال وقت قصير. نشكر لك صبرك.',
                    'en' => 'We are reviewing your request now and will get back to you shortly. Thank you for your patience.',
                ],
            ],
            [
                'shortcut' => 'refund',
                'title' => ['ar' => 'الاسترداد', 'en' => 'Refund'],
                'body' => [
                    'ar' => 'تم رفع طلب الاسترداد إلى الفريق المالي، وتتم معالجته عادةً خلال 3 إلى 5 أيام عمل. سنُبلغك فور اكتماله.',
                    'en' => 'Your refund request has been passed to the finance team and is usually processed within 3 to 5 working days. We will let you know once it is done.',
                ],
            ],
            [
                'shortcut' => 'resolved',
                'title' => ['ar' => 'تم الحل', 'en' => 'Resolved'],
                'body' => [
                    'ar' => 'يسعدنا أن مشكلتك قد حُلّت. إن احتجت أي مساعدة أخرى فنحن هنا دائماً.',
                    'en' => 'We are glad your problem is solved. If you need any more help, we are always here.',
                ],
            ],
            [
                'shortcut' => 'thanks',
                'title' => ['ar' => 'شكر وإغلاق', 'en' => 'Thanks and closing'],
                'body' => [
                    'ar' => 'شكراً لتواصلك مع دور. سنغلق هذه التذكرة، ويمكنك إعادة فتحها في أي وقت إن احتجت.',
                    'en' => 'Thank you for contacting Dorr. We will close this ticket, and you can reopen it at any time if you need to.',
                ],
            ],
        ];
    }
}
