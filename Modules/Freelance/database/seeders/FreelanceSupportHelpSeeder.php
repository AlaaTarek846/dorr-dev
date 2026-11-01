<?php

namespace Modules\Freelance\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\User\Models\SupportHelpNode;

/**
 * The freelancer app's quick-chat menu (the guided help before a ticket), in Arabic and English - topics with
 * `audience = freelancer`, edited on the same dashboard page as the riders' and captains' (Support > Quick chat).
 *
 * Safe to run again: a topic is matched by its parent and its English title, so a missing one is added and one that
 * exists is left exactly as the admin has it. A topic with `children` is a menu; one with only an `answer` ends the path
 * (the freelancer then picks "solved" or "I need an agent").
 */
class FreelanceSupportHelpSeeder extends Seeder
{
    public function run(): void
    {
        $this->sync($this->tree());
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function sync(array $nodes, ?int $parentId = null): void
    {
        foreach ($nodes as $index => $row) {
            $node = SupportHelpNode::query()
                ->where('parent_id', $parentId)
                ->where('audience', 'freelancer')
                ->whereHas('translations', fn ($query) => $query->where('locale', 'en')->where('title', $row['title']['en']))
                ->first();

            if (! $node) {
                $node = SupportHelpNode::query()->create(['parent_id' => $parentId, 'sort_order' => $index + 1, 'status' => true, 'audience' => 'freelancer']);

                foreach (['ar', 'en'] as $locale) {
                    $node->translations()->create([
                        'locale' => $locale,
                        'title' => $row['title'][$locale],
                        'answer' => $row['answer'][$locale] ?? null,
                    ]);
                }
            }

            $this->sync($row['children'] ?? [], $node->id);
        }
    }

    /**
     * @param  array{ar: string, en: string}  $title
     * @param  array{ar: string, en: string}|null  $answer
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function topic(array $title, ?array $answer = null, array $children = []): array
    {
        return ['title' => $title, 'answer' => $answer, 'children' => $children];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tree(): array
    {
        return [
            $this->topic(['ar' => 'التوثيق والحساب', 'en' => 'Verification and account'], null, [
                $this->topic(
                    ['ar' => 'كيف أوثّق حسابي؟', 'en' => 'How do I verify my account?'],
                    [
                        'ar' => 'افتح «التوثيق» من الصفحة الرئيسية أو من «ملفي»، وارفع المستندات المطلوبة بصور واضحة. بيراجعها فريقنا وتوصلك النتيجة في الإشعارات.',
                        'en' => 'Open "Verification" from Home or from My profile and upload the required documents as clear photos. Our team reviews them and you get the result in your notifications.',
                    ],
                ),
                $this->topic(
                    ['ar' => 'تم رفض التوثيق', 'en' => 'My verification was rejected'],
                    [
                        'ar' => 'هتلاقي سبب الرفض في صفحة التوثيق. صحّح المستند المذكور وأعد الإرسال. لو السبب مش واضح اطلب موظف.',
                        'en' => 'The reason is shown on the Verification page. Fix the document it mentions and send it again. If the reason is unclear, ask for an agent.',
                    ],
                ),
                $this->topic(
                    ['ar' => 'حسابي موقوف', 'en' => 'My account is suspended'],
                    [
                        'ar' => 'سبب الإيقاف بيظهر في الصفحة الرئيسية تحت حالة الحساب. تقدر تعترض مرة واحدة خلال 14 يوم من «العقوبات». للمزيد اطلب موظف.',
                        'en' => 'The reason is shown on Home under your account status. You can appeal once within 14 days from Sanctions. For more, ask for an agent.',
                    ],
                ),
            ]),
            $this->topic(['ar' => 'المشاريع والعروض', 'en' => 'Projects and offers'], null, [
                $this->topic(
                    ['ar' => 'لا أستطيع إرسال عرض', 'en' => 'I cannot send an offer'],
                    [
                        'ar' => 'لازم يكون حسابك موثّقًا وغير موقوف أو في إجازة، ولازم يكون المشروع لسه مفتوح. اتأكد من حالة الحساب في الصفحة الرئيسية.',
                        'en' => 'Your account must be verified and not suspended or on leave, and the project must still be open. Check your account status on Home.',
                    ],
                ),
                $this->topic(
                    ['ar' => 'كيف يتم تسليم العمل؟', 'en' => 'How do I deliver my work?'],
                    [
                        'ar' => 'من صفحة المشروع افتح المرحلة وأرسل التسليم بالملفات والوصف. العميل يراجعه ويعتمده أو يطلب تعديلًا.',
                        'en' => 'From the project page open the milestone and send the delivery with files and a description. The client reviews it and approves it or asks for changes.',
                    ],
                ),
            ]),
            $this->topic(['ar' => 'المحفظة والمدفوعات', 'en' => 'Wallet and payments'], null, [
                $this->topic(
                    ['ar' => 'كيف أسحب أرباحي؟', 'en' => 'How do I withdraw my earnings?'],
                    [
                        'ar' => 'من المحفظة اختر «سحب»، وأضف وسيلة السحب ثم اطلب المبلغ. بيراجع الطلب فريقنا ويتم التحويل بعد الموافقة.',
                        'en' => 'From the wallet choose Withdraw, add a withdrawal method and request the amount. Our team reviews the request and the transfer is made once approved.',
                    ],
                ),
                $this->topic(
                    ['ar' => 'تأخر وصول الرصيد', 'en' => 'My balance has not arrived'],
                    [
                        'ar' => 'يظهر الرصيد عادةً خلال دقائق من الشحن، وقد يستغرق حتى 24 ساعة حسب وسيلة الدفع. تأكد أن العملية اكتملت في بنكك ثم حدّث المحفظة.',
                        'en' => 'The balance usually appears within minutes and can take up to 24 hours depending on the payment method. Check the payment completed with your bank, then refresh the wallet.',
                    ],
                ),
            ]),
            $this->topic(['ar' => 'مشكلة مع عميل', 'en' => 'A problem with a client'], null, [
                $this->topic(
                    ['ar' => 'عميل يسيء التعامل', 'en' => 'A client is being abusive'],
                    [
                        'ar' => 'من المحادثة اضغط ⋮ ثم «إبلاغ» واذكر السبب. الفريق بيراجع البلاغ ويتصرف حسب السياسة.',
                        'en' => 'In the chat tap the menu then Report and give the reason. The team reviews the report and acts according to the policy.',
                    ],
                ),
                $this->topic(
                    ['ar' => 'خلاف على التسليم أو الدفع', 'en' => 'A dispute about delivery or payment'],
                    [
                        'ar' => 'افتح نزاعًا من صفحة المشروع. الفريق بيراجع الأدلة من الطرفين ويقرر.',
                        'en' => 'Open a dispute from the project page. The team reviews the evidence from both sides and decides.',
                    ],
                ),
            ]),
        ];
    }
}
