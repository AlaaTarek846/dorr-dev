<?php

namespace Database\Seeders\General;

use App\Models\Faq;
use App\Models\ServiceCategory;
use Database\Seeders\Concerns\SyncsSeedTranslations;
use Illuminate\Database\Seeder;

class FaqSeeder extends Seeder
{
    use SyncsSeedTranslations;

    /**
     * Re-running the seeder updates the seeded rows in place instead of
     * duplicating them: rows are matched on their English question.
     */
    public function run(): void
    {
        $services = $this->resolveServices();

        foreach ($this->rows() as $index => $row) {
            $faq = $this->findExisting($row['question']['en']) ?? new Faq;

            $faq->fill([
                'service_id' => $services[$row['service']] ?? null,
                'status' => $row['status'],
                'sort_order' => $index + 1,
            ]);
            $faq->save();

            $this->syncTranslationFields($faq, [
                'en' => [
                    'question' => $row['question']['en'],
                    'answer' => $row['answer']['en'],
                ],
                'ar' => [
                    'question' => $row['question']['ar'],
                    'answer' => $row['answer']['ar'],
                ],
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    protected function resolveServices(): array
    {
        $modules = array_values(array_unique(array_column($this->rows(), 'service')));

        return ServiceCategory::query()
            ->whereIn('module_name', $modules)
            ->pluck('id', 'module_name')
            ->all();
    }

    protected function findExisting(string $englishQuestion): ?Faq
    {
        return Faq::query()
            ->whereHas('translations', fn ($query) => $query
                ->where('locale', 'en')
                ->where('question', $englishQuestion))
            ->first();
    }

    /**
     * @return list<array{
     *     service: string|null,
     *     status: bool,
     *     question: array{en: string, ar: string},
     *     answer: array{en: string, ar: string}
     * }>
     */
    protected function rows(): array
    {
        return [
            [
                'service' => null,
                'status' => true,
                'question' => [
                    'en' => 'What is Dorr?',
                    'ar' => 'ما هي منصة دور؟',
                ],
                'answer' => [
                    'en' => 'Dorr is a multi-service platform that connects customers with '
                        .'verified providers for rides, delivery, home services and more.',
                    'ar' => 'دور منصة متعددة الخدمات تربط العملاء بمزودين معتمدين لخدمات '
                        .'التوصيل، التوصيل للمنزل، وغيرها من الخدمات.',
                ],
            ],
            [
                'service' => null,
                'status' => true,
                'question' => [
                    'en' => 'How do I create an account?',
                    'ar' => 'كيف أقوم بإنشاء حساب؟',
                ],
                'answer' => [
                    'en' => 'Download the Dorr app, choose "Sign up" and verify your phone '
                        .'number with the code we send you.',
                    'ar' => 'حمّل تطبيق دور، اختر "تسجيل" وتحقق من رقم جوالك عبر الكود الذي '
                        .'نرسله إليك.',
                ],
            ],
            [
                'service' => null,
                'status' => true,
                'question' => [
                    'en' => 'How can I contact support?',
                    'ar' => 'كيف أستطيع التواصل مع الدعم الفني؟',
                ],
                'answer' => [
                    'en' => 'Open the app and use the Help section, or send us a message from '
                        .'your account settings. Our team replies within 24 hours.',
                    'ar' => 'افتح التطبيق واستخدم قسم "المساعدة"، أو أرسل لنا رسالة من إعدادات '
                        .'حسابك. يرد فريقنا خلال ٢٤ ساعة.',
                ],
            ],
            [
                'service' => null,
                'status' => true,
                'question' => [
                    'en' => 'How do I top up my wallet?',
                    'ar' => 'كيف أضيف رصيداً إلى محفظتي؟',
                ],
                'answer' => [
                    'en' => 'Go to Wallet, choose "Top up" and pick your preferred payment '
                        .'method. The balance is credited instantly.',
                    'ar' => 'اذهب إلى "المحفظة"، اختر "شحن" وحدد طريقة الدفع المفضلة لديك. '
                        .'يُضاف الرصيد فوراً.',
                ],
            ],
            [
                'service' => null,
                'status' => true,
                'question' => [
                    'en' => 'Why was my withdrawal request rejected?',
                    'ar' => 'لماذا تم رفض طلب السحب؟',
                ],
                'answer' => [
                    'en' => 'A withdrawal can be rejected when the request exceeds the '
                        .'available balance, or when the account has an unpaid pending charge. '
                        .'You will always see the reason next to the request.',
                    'ar' => 'قد يُرفض طلب السحب إذا تجاوز المبلغ الرصيد المتاح، أو إذا كان '
                        .'الحساب يحتوي على فاتورة معلّقة غير مسددة. ستجد السبب دائماً بجانب '
                        .'الطلب.',
                ],
            ],
            [
                'service' => null,
                'status' => true,
                'question' => [
                    'en' => 'Can I transfer money to another user?',
                    'ar' => 'هل يمكنني تحويل الأموال إلى مستخدم آخر؟',
                ],
                'answer' => [
                    'en' => 'Yes. Open your profile, enter the recipient phone number and the '
                        .'amount, then confirm with your PIN.',
                    'ar' => 'نعم. افتح ملفك الشخصي، أدخل رقم جوال المستلم والمبلغ، ثم أكّد '
                        .'العملية باستخدام الرقم السري.',
                ],
            ],
            [
                'service' => 'passenger_ride',
                'status' => true,
                'question' => [
                    'en' => 'How do I book a ride?',
                    'ar' => 'كيف أحجز رحلة؟',
                ],
                'answer' => [
                    'en' => 'Enter your pickup and drop-off locations, choose a vehicle type '
                        .'and confirm the booking. You can track the driver on the map.',
                    'ar' => 'أدخل موقع الانطلاق والوجهة، اختر نوع المركبة وأكّد الحجز. يمكنك '
                        .'تتبّع السائق على الخريطة.',
                ],
            ],
            [
                'service' => 'passenger_ride',
                'status' => true,
                'question' => [
                    'en' => 'Can I cancel a booking?',
                    'ar' => 'هل يمكنني إلغاء الحجز؟',
                ],
                'answer' => [
                    'en' => 'You can cancel free of charge before the driver arrives. Open the '
                        .'active order and select "Cancel booking".',
                    'ar' => 'يمكنك الإلغاء مجاناً قبل وصول السائق. افتح الطلب النشط واختر '
                        .'"إلغاء الحجز".',
                ],
            ],
            [
                'service' => 'parcels',
                'status' => true,
                'question' => [
                    'en' => 'What information is required to send a parcel?',
                    'ar' => 'ما المعلومات المطلوبة لإرسال طرد؟',
                ],
                'answer' => [
                    'en' => 'You need the pickup address, the drop-off address, a description '
                        .'of the parcel and the recipient phone number.',
                    'ar' => 'تحتاج إلى عنوان الاستلام وعنوان التسليم ووصف الطرد ورقم جوال '
                        .'المستلم.',
                ],
            ],
            [
                'service' => 'car_rental',
                'status' => true,
                'question' => [
                    'en' => 'What do I need to rent a car?',
                    'ar' => 'ما الذي أحتاجه لاستئجار سيارة؟',
                ],
                'answer' => [
                    'en' => 'A valid driving licence, a copy of your ID and a credit card for '
                        .'the security deposit. The licence must be held for at least one year.',
                    'ar' => 'رخصة قيادة سارية، ونسخة من هويتك، وبطاقة ائتمان لتأمين الوديعة. '
                        .'يجب أن تكون الرخصة مضى على إصدارها سنة على الأقل.',
                ],
            ],
            [
                'service' => 'fuel',
                'status' => true,
                'question' => [
                    'en' => 'How is fuel delivered?',
                    'ar' => 'كيف يتم توصيل الوقود؟',
                ],
                'answer' => [
                    'en' => 'Choose fuel delivery when booking, confirm the amount and the '
                        .'payment method, and the driver brings the fuel to your location.',
                    'ar' => 'اختر خدمة توصيل الوقود عند الحجز، وحدد الكمية وطريقة الدفع، ويقوم '
                        .'السائق بتوصيل الوقود إلى موقعك.',
                ],
            ],
            [
                'service' => 'driver_without_vehicle',
                'status' => false,
                'question' => [
                    'en' => 'How do I register as a provider?',
                    'ar' => 'كيف أسجّل كمزود خدمة؟',
                ],
                'answer' => [
                    'en' => 'Create a provider account, complete your profile and upload the '
                        .'required documents. Our team reviews every application.',
                    'ar' => 'أنشئ حساب مزود، أكمل ملفك الشخصي وارفع المستندات المطلوبة. يراجع '
                        .'فريقنا كل طلب.',
                ],
            ],
        ];
    }
}
