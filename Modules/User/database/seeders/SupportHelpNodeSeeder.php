<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\User\Models\SupportHelpNode;

/**
 * The app's quick-chat menu (the guided help before a ticket), in Arabic and English, up to the 6 levels the
 * tree allows.
 *
 * Safe to run again: a topic is matched by its parent and its English title, so a missing one is added in its
 * place and one that exists is left exactly as the admin has it (texts, order, status). A topic the admin
 * deleted comes back, so run it on a fresh table or knowingly.
 *
 * A topic with `children` is a menu; one with only an `answer` ends the path (the customer then picks
 * "solved" or "I need an agent").
 */
class SupportHelpNodeSeeder extends Seeder
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
                ->whereHas('translations', fn ($query) => $query->where('locale', 'en')->where('title', $row['title']['en']))
                ->first();

            if (! $node) {
                $node = SupportHelpNode::query()->create(['parent_id' => $parentId, 'sort_order' => $index + 1, 'status' => true]);

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
    protected function tree(): array
    {
        return [
            // ------------------------------------------------------------------ wallet and payments
            $this->topic(['ar' => 'المحفظة والمدفوعات', 'en' => 'Wallet and payments'], null, [
                $this->topic(['ar' => 'شحن المحفظة', 'en' => 'Topping up my wallet'], null, [
                    $this->topic(
                        ['ar' => 'تأخر وصول الرصيد', 'en' => 'My balance has not arrived'],
                        [
                            'ar' => 'يظهر الرصيد عادةً خلال دقائق من الشحن، وقد يستغرق حتى 24 ساعة حسب وسيلة الدفع. تأكد أن العملية ظهرت مكتملة في بنكك، ثم حدّث شاشة المحفظة.',
                            'en' => 'The balance usually appears within minutes of the top-up and can take up to 24 hours depending on the payment method. Check that the payment shows as completed with your bank, then refresh the wallet screen.',
                        ],
                    ),
                    $this->topic(
                        ['ar' => 'خُصم المبلغ ولم يُضَف', 'en' => 'I was charged but nothing was added'],
                        [
                            'ar' => 'إن خُصم المبلغ دون إضافته فسيُردّ تلقائياً إلى وسيلة الدفع خلال 3 إلى 5 أيام عمل. احتفظ برقم العملية من إشعار البنك لنتمكن من تتبعها إن احتجت موظفاً.',
                            'en' => 'If you were charged and nothing was added, the amount is returned automatically to your payment method within 3 to 5 working days. Keep the transaction number from your bank notice so we can trace it if you need an agent.',
                        ],
                    ),
                    $this->topic(
                        ['ar' => 'رسوم الشحن', 'en' => 'Top-up fees'],
                        [
                            'ar' => 'تظهر رسوم كل وسيلة دفع قبل تأكيد الشحن، ولا يُخصم أي مبلغ غير ما تراه في شاشة التأكيد.',
                            'en' => 'The fee of each payment method is shown before you confirm the top-up, and nothing is charged beyond what the confirmation screen shows.',
                        ],
                    ),
                ]),
                $this->topic(['ar' => 'السحب والتحويل', 'en' => 'Withdrawals and transfers'], null, [
                    $this->topic(
                        ['ar' => 'رُفض طلب السحب', 'en' => 'My withdrawal was rejected'],
                        [
                            'ar' => 'قد يُرفض السحب إذا تجاوز المبلغ رصيدك المتاح أو الحد المسموح، أو إذا كانت بيانات الحساب البنكي غير صحيحة. صحّح البيانات وأعد الطلب.',
                            'en' => 'A withdrawal can be rejected when the amount is above your available balance or the allowed limit, or when the bank details are wrong. Correct them and submit the request again.',
                        ],
                    ),
                    $this->topic(
                        ['ar' => 'كم يستغرق السحب؟', 'en' => 'How long does a withdrawal take?'],
                        [
                            'ar' => 'تُعالَج طلبات السحب عادةً خلال يوم إلى 3 أيام عمل بعد الموافقة عليها.',
                            'en' => 'Withdrawals are usually processed within 1 to 3 working days after they are approved.',
                        ],
                    ),
                    $this->topic(
                        ['ar' => 'تحويل رصيد لمستخدم آخر', 'en' => 'Sending money to another user'],
                        [
                            'ar' => 'من المحفظة اختر تحويل، وأدخل رقم جوال المستلم والمبلغ، ثم أكّد بالرمز السري للمحفظة.',
                            'en' => 'From the wallet choose Transfer, enter the recipient\'s phone number and the amount, then confirm with your wallet PIN.',
                        ],
                    ),
                ]),
                $this->topic(
                    ['ar' => 'فشلت عملية الدفع', 'en' => 'My payment failed'],
                    [
                        'ar' => 'إن فشل الدفع فلن يُخصم منك مبلغ، وإن خُصم فسيُعاد تلقائياً خلال 3 إلى 5 أيام عمل. جرّب وسيلة دفع أخرى أو تأكد من بيانات البطاقة.',
                        'en' => 'If a payment fails you are not charged, and if an amount was taken it is returned automatically within 3 to 5 working days. Try another payment method or check your card details.',
                    ],
                ),
            ]),

            // ------------------------------------------------------------------ account
            $this->topic(['ar' => 'حسابي', 'en' => 'My account'], null, [
                $this->topic(
                    ['ar' => 'تغيير رقم الجوال', 'en' => 'Change my phone number'],
                    [
                        'ar' => 'من الملف الشخصي اختر البيانات الشخصية ثم تغيير رقم الجوال، وسيصل رمز تحقق إلى الرقم الجديد لتأكيده.',
                        'en' => 'Open Profile, choose Personal data, then Change phone number. A verification code is sent to the new number to confirm it.',
                    ],
                ),
                $this->topic(
                    ['ar' => 'لم يصلني رمز التحقق', 'en' => 'I did not get the verification code'],
                    [
                        'ar' => 'تأكد من صحة الرقم ومن تغطية الشبكة، وانتظر دقيقة قبل طلب رمز جديد. إن لم يصل بعد عدة محاولات فاختر "أحتاج موظفاً".',
                        'en' => 'Check that the number is right and that you have signal, and wait a minute before asking for a new code. If it still does not arrive after a few tries, choose "I need an agent".',
                    ],
                ),
                $this->topic(['ar' => 'الأمان والخصوصية', 'en' => 'Security and privacy'], null, [
                    $this->topic(
                        ['ar' => 'أشك أن حسابي اختُرق', 'en' => 'I think my account was hacked'],
                        [
                            'ar' => 'غيّر الرمز السري للمحفظة فوراً وسجّل الخروج من الأجهزة الأخرى. ولأن الأمر يخص أمان حسابك فاضغط "أحتاج موظفاً" ليراجعه فريقنا فوراً.',
                            'en' => 'Change your wallet PIN right away and sign out of other devices. Because this concerns your account security, tap "I need an agent" so our team can review it immediately.',
                        ],
                    ),
                    $this->topic(
                        ['ar' => 'نسيت الرمز السري للمحفظة', 'en' => 'I forgot my wallet PIN'],
                        [
                            'ar' => 'من إعدادات المحفظة اختر نسيت الرمز السري، وسنرسل رمز تحقق إلى جوالك لتعيين رمز جديد.',
                            'en' => 'In the wallet settings choose Forgot PIN and we will send a verification code to your phone to set a new one.',
                        ],
                    ),
                ]),
                $this->topic(
                    ['ar' => 'حذف حسابي', 'en' => 'Delete my account'],
                    [
                        'ar' => 'يمكنك طلب حذف الحساب من الملف الشخصي ثم الإعدادات. الحذف لا يمكن التراجع عنه، فتأكد من سحب رصيد محفظتك أولاً.',
                        'en' => 'You can delete your account from Profile, then Settings. It cannot be undone, so withdraw your wallet balance first.',
                    ],
                ),
            ]),

            // ------------------------------------------------------------------ orders and bookings (the deep branch)
            $this->topic(['ar' => 'الطلبات والحجوزات', 'en' => 'Orders and bookings'], null, [
                $this->topic(['ar' => 'طلب جارٍ', 'en' => 'An order in progress'], null, [
                    $this->topic(['ar' => 'تأخر مقدّم الخدمة', 'en' => 'The provider is late'], null, [
                        $this->topic(['ar' => 'لم يصل بعد الوقت المتوقع', 'en' => 'Past the expected time'], null, [
                            $this->topic(
                                ['ar' => 'أريد إلغاء الطلب', 'en' => 'I want to cancel'],
                                [
                                    'ar' => 'إن تجاوز التأخير الوقت المتوقع كثيراً فيمكنك إلغاء الطلب دون رسوم من شاشة تفاصيل الطلب.',
                                    'en' => 'If the delay goes well past the expected time you can cancel without a fee from the order details screen.',
                                ],
                            ),
                            $this->topic(
                                ['ar' => 'أريد الانتظار', 'en' => 'I will keep waiting'],
                                [
                                    'ar' => 'يمكنك متابعة موقع مقدّم الخدمة ومراسلته من شاشة الطلب. سنُبلغك فور وصوله.',
                                    'en' => 'You can follow the provider\'s location and message them from the order screen. We will let you know as soon as they arrive.',
                                ],
                            ),
                        ]),
                        $this->topic(
                            ['ar' => 'لا أستطيع التواصل معه', 'en' => 'I cannot reach the provider'],
                            [
                                'ar' => 'جرّب المراسلة من داخل الطلب، فهي تصله حتى لو كان هاتفه مشغولاً. وإن لم يرد فاختر "أحتاج موظفاً".',
                                'en' => 'Try messaging from inside the order, which reaches them even when their phone is busy. If there is no answer, choose "I need an agent".',
                            ],
                        ),
                    ]),
                    $this->topic(
                        ['ar' => 'إلغاء الطلب', 'en' => 'Cancel the order'],
                        [
                            'ar' => 'يمكنك الإلغاء مجاناً قبل أن يبدأ مقدّم الخدمة التنفيذ. بعد البدء قد تُطبَّق رسوم إلغاء.',
                            'en' => 'You can cancel for free before the provider starts. After that a cancellation fee may apply.',
                        ],
                    ),
                ]),
                $this->topic(['ar' => 'طلب منتهٍ', 'en' => 'A finished order'], null, [
                    $this->topic(
                        ['ar' => 'لم أستلم الخدمة كاملة', 'en' => 'The service was not completed'],
                        [
                            'ar' => 'نأسف لذلك. اختر "أحتاج موظفاً" وأرفق وصفاً وصوراً إن وُجدت ليراجع فريقنا الطلب ويعوّضك إن لزم.',
                            'en' => 'We are sorry about that. Choose "I need an agent" and add a description and photos if you have them, so our team can review the order and make it right.',
                        ],
                    ),
                    $this->topic(
                        ['ar' => 'استرداد المبلغ', 'en' => 'Getting a refund'],
                        [
                            'ar' => 'تُراجَع طلبات الاسترداد خلال 3 أيام عمل، ويعود المبلغ إلى محفظتك عند الموافقة.',
                            'en' => 'Refund requests are reviewed within 3 working days, and the amount goes back to your wallet when approved.',
                        ],
                    ),
                    $this->topic(
                        ['ar' => 'تقييم مقدّم الخدمة', 'en' => 'Rating the provider'],
                        [
                            'ar' => 'من تفاصيل الطلب المنتهي اختر تقييم، ويمكنك إضافة تعليق. تقييمك يساعد غيرك على الاختيار.',
                            'en' => 'Open the finished order and choose Rate, with an optional comment. Your rating helps others choose.',
                        ],
                    ),
                ]),
            ]),

            // ------------------------------------------------------------------ app problems
            $this->topic(['ar' => 'مشكلة في التطبيق', 'en' => 'A problem with the app'], null, [
                $this->topic(
                    ['ar' => 'التطبيق بطيء أو يتوقف', 'en' => 'The app is slow or crashes'],
                    [
                        'ar' => 'حدّث التطبيق لآخر إصدار وتأكد من اتصالك بالإنترنت، ثم أغلقه وافتحه من جديد. إن استمرت المشكلة فاختر "أحتاج موظفاً" مع ذكر طراز جوالك.',
                        'en' => 'Update the app to the latest version, check your connection, then close it and open it again. If it continues, choose "I need an agent" and mention your phone model.',
                    ],
                ),
                $this->topic(
                    ['ar' => 'لا تصلني الإشعارات', 'en' => 'I do not get notifications'],
                    [
                        'ar' => 'تأكد من تفعيل الإشعارات للتطبيق في إعدادات الجوال ومن إعدادات الإشعارات داخل الملف الشخصي، واستثنِ التطبيق من توفير الطاقة.',
                        'en' => 'Check that notifications are on for the app in your phone settings and in the notification settings inside your profile, and exclude the app from battery saving.',
                    ],
                ),
            ]),

            // ------------------------------------------------------------------ one-step topic
            $this->topic(
                ['ar' => 'لا أستطيع تنفيذ طلبي', 'en' => 'I cannot place my order'],
                [
                    'ar' => 'تأكد من اتصالك بالإنترنت ومن تحديث التطبيق لآخر إصدار، ثم أعد المحاولة. وإن استمرت المشكلة فاضغط "أحتاج موظفاً" لنساعدك.',
                    'en' => 'Check your internet connection and that the app is up to date, then try again. If the problem continues, tap "I need an agent" and we will help.',
                ],
            ),
        ];
    }
}
