<?php

namespace Database\Seeders\General;

use App\Models\PrivacyPolicy;
use Database\Seeders\Concerns\SyncsSeedTranslations;
use Illuminate\Database\Seeder;

class PrivacyPolicySeeder extends Seeder
{
    use SyncsSeedTranslations;

    /**
     * Privacy policies are rich text, so the seeded content is the same HTML
     * shape the admin editor stores. Re-running the seeder updates the row in
     * place instead of duplicating it.
     */
    public function run(): void
    {
        foreach ($this->rows() as $index => $row) {
            $policy = $this->findExisting($row['content']['en']) ?? new PrivacyPolicy;

            $policy->fill([
                'service_id' => null,
                'status' => $row['status'],
                'sort_order' => $index + 1,
            ]);
            $policy->save();

            $this->syncTranslationFields($policy, [
                'en' => ['content' => $row['content']['en']],
                'ar' => ['content' => $row['content']['ar']],
            ]);
        }
    }

    protected function findExisting(string $englishContent): ?PrivacyPolicy
    {
        return PrivacyPolicy::query()
            ->whereHas('translations', fn ($query) => $query
                ->where('locale', 'en')
                ->where('content', $englishContent))
            ->first();
    }

    /**
     * @return list<array{status: bool, content: array{en: string, ar: string}}>
     */
    protected function rows(): array
    {
        return [
            [
                'status' => true,
                'content' => [
                    'en' => $this->englishPolicy(),
                    'ar' => $this->arabicPolicy(),
                ],
            ],
        ];
    }

    protected function englishPolicy(): string
    {
        return <<<'HTML'
<h2>1. Introduction</h2>
<p>Welcome to Dorr. This privacy policy explains how we collect, use, store and share
your personal information when you use our platform, our mobile applications and our
website. By creating an account or using our services, you agree to the practices
described below.</p>

<h2>2. Information we collect</h2>
<p>We collect the information you provide directly, together with information generated
when you use the platform:</p>
<ul>
<li><strong>Account information</strong> — your name, phone number, email address and profile photo.</li>
<li><strong>Verification data</strong> — the phone number you verify and any identity documents submitted by providers.</li>
<li><strong>Transaction data</strong> — payments, wallet transactions, invoices and transfer records.</li>
<li><strong>Usage data</strong> — device identifiers, IP address, application version, pages visited and error logs.</li>
<li><strong>Location data</strong> — pickup and drop-off locations you enter when booking a service, and driver location during an active trip.</li>
<li><strong>Support messages</strong> — the content of conversations with our support team.</li>
</ul>

<h2>3. How we use your information</h2>
<p>We use your information to:</p>
<ul>
<li>Provide, operate and improve the services you request.</li>
<li>Verify your identity and prevent fraud, abuse and unauthorized access.</li>
<li>Process payments, settle provider payouts and keep accurate financial records.</li>
<li>Send you service updates, transaction confirmations and support replies.</li>
<li>Detect and investigate fraud, security incidents and violations of our terms.</li>
</ul>

<h2>4. Sharing your information</h2>
<p>We do not sell your personal information. We share it only with the parties that
are necessary to deliver the service, which includes:</p>
<ul>
<li>Service providers who fulfil your booking and share your pickup details to complete the job.</li>
<li>Payment processors and banks that handle the financial side of a transaction.</li>
<li>Telecommunications providers, when required to deliver an SMS verification code.</li>
<li>Government authorities, only where we are legally obliged to disclose information.</li>
</ul>

<h2>5. Retention</h2>
<p>We keep your personal information for as long as your account is active and for the
period afterwards that we are required by law to retain financial and transactional
records. When information is no longer needed, we delete or anonymize it.</p>

<h2>6. Security</h2>
<p>All data in transit is protected by encrypted connections. Sensitive values, such as
payment credentials, are stored in an encrypted form and are never stored in plain text.
Access to customer data is restricted to authorised employees and service providers who
need it to perform their duties. No system is completely secure, so please protect your
PIN and account password and tell us immediately if you suspect unauthorised access.</p>

<h2>7. Your rights</h2>
<p>Depending on where you live, you may have the right to:</p>
<ul>
<li>Request a copy of the personal information we hold about you.</li>
<li>Ask us to correct information that is inaccurate or incomplete.</li>
<li>Request deletion of your account and its personal information.</li>
<li>Withdraw your consent for optional communications.</li>
<li>Object to, or restrict, certain uses of your information.</li>
</ul>
<p>To exercise any of these rights, contact us through the Help section in the app. We may
ask you to verify your identity before we process your request.</p>

<h2>8. Cookies and similar technologies</h2>
<p>Our website and applications use cookies and similar technologies that are required
for the service to work, such as keeping you signed in and remembering your preferences.
We do not use advertising cookies.</p>

<h2>9. Children</h2>
<p>Our services are intended for adults and for users who are old enough to enter a binding
contract under the laws of their country. We do not knowingly collect information from
children below that age, and if you believe a child has provided us with information,
please contact us so that we can delete it.</p>

<h2>10. Changes to this policy</h2>
<p>We may update this privacy policy from time to time. When we make a material change, we
will notify you through the app or by email before the change takes effect. The date of the
latest update always appears with the published policy.</p>

<h2>11. Contact us</h2>
<p>If you have any questions about this privacy policy or about how we handle your
information, please contact our support team from the Help section of the Dorr app.</p>
HTML;
    }

    protected function arabicPolicy(): string
    {
        return <<<'HTML'
<h2>١. مقدمة</h2>
<p>مرحباً بك في منصة "دور". توضّح سياسة الخصوصية هذه كيف نجمع بياناتك الشخصية ونستخدمها
ونخزّنها ونشاركها عند استخدامك المنصة أو تطبيقاتنا أو موقعنا الإلكتروني. بإنشائك حساباً أو
استخدامك خدماتنا فإنك توافق على الممارسات الموضحة أدناه.</p>

<h2>٢. المعلومات التي نجمعها</h2>
<p>نجمع المعلومات التي تقدّمها مباشرة، إلى جانب المعلومات الناتجة عن استخدامك المنصة:</p>
<ul>
<li><strong>بيانات الحساب</strong> — اسمك ورقم جوالك وبريدك الإلكتروني وصورة ملفك الشخصي.</li>
<li><strong>بيانات التحقق</strong> — رقم الجوال الذي تحققت منه وأي مستندات هوية يقدّمها المزودون.</li>
<li><strong>بيانات المعاملات</strong> — المدفوعات ومعاملات المحفظة والفواتير وسجلات التحويل.</li>
<li><strong>بيانات الاستخدام</strong> — معرّفات الجهاز وعنوان IP وإصدار التطبيق والصفحات التي زرتها وسجلات الأخطاء.</li>
<li><strong>بيانات الموقع</strong> — مواقع الانطلاق والوجهة التي تدخلها عند حجز خدمة، وموقع السائق أثناء الرحلة.</li>
<li><strong>رسائل الدعم</strong> — محتوى المحادثات مع فريق الدعم الفني.</li>
</ul>

<h2>٣. كيف نستخدم معلوماتك</h2>
<p>نستخدم معلوماتك من أجل:</p>
<ul>
<li>تقديم الخدمات التي تطلبها وتشغيلها وتطويرها.</li>
<li>التحقق من هويتك ومنع الاحتيال وإساءة الاستخدام والوصول غير المصرّح به.</li>
<li>معالجة المدفوعات وتسوية مستحقات المزودين وحفظ سجلات مالية دقيقة.</li>
<li>إرسال تحديثات الخدمة وتأكيدات المعاملات وردود الدعم.</li>
<li>كشف الاحتيال والحوادث الأمنية ومخالفات شروط الاستخدام والتحقيق فيها.</li>
</ul>

<h2>٤. مشاركة معلوماتك</h2>
<p>لا نبيع بياناتك الشخصية. نشاركها فقط مع الجهات الضرورية لتقديم الخدمة، وهي:</p>
<ul>
<li>مزودو الخدمات الذين ينفّذون حجزك ويتشاركون عنوان الانطلاق لإتمام العمل.</li>
<li>بوابات الدفع والبنوك التي تتولى الجانب المالي من المعاملة.</li>
<li>شركات الاتصالات، عند الحاجة لإرسال رمز التحقق عبر رسالة نصية.</li>
<li>الجهات الحكومية، وفي حالة إلزامنا القانوني بالإفصاح عن المعلومات فقط.</li>
</ul>

<h2>٥. مدة الاحتفاظ بالبيانات</h2>
<p>نحتفظ بمعلوماتك الشخصية طوال فترة نشاط حسابك، ولمدة بعدها التي يفرضها القانون للاحتفاظ
بالسجلات المالية والمعاملات. وعدم الحاجة إلى المعلومات، نحذفها أو نجعلها مجهولة الهوية.</p>

<h2>٦. الأمان</h2>
<p>يتم حماية جميع البيانات أثناء النقل عبر اتصالات مشفّرة. تُخزَّن القيم الحساسة، مثل بيانات
الدفع، بصيغة مشفّرة ولا تُحفظ أبداً كنص صريح. يقتصر الوصول إلى بيانات العملاء على الموظفين
ومزودي الخدمة المصرّح لهم، وذلك حسب الحاجة لأداء عملهم. ولا يوجد نظام آمن بنسبة مائة بالمئة،
لذلك نرجو حماية الرقم السري وكلمة المرور وإعلامنا فوراً إذا اشتبهت في أي وصول غير مصرّح به.</p>

<h2>٧. حقوقك</h2>
<p>بناءً على مكان إقامتك، قد يكون لك الحق في:</p>
<ul>
<li>الحصول على نسخة من المعلومات الشخصية التي نحتفظ بها عنك.</li>
<li>طلب تصحيح المعلومات غير الدقيقة أو الناقصة.</li>
<li>طلب حذف حسابك ومعلوماتك الشخصية.</li>
<li>سحب موافقتك على الرسائل الاختيارية.</li>
<li>الاعتراض على استخدامات معينة لمعلوماتك أو تقييدها.</li>
</ul>
<p>لممارسة أي من هذه الحقوق، تواصل معنا عبر قسم "المساعدة" في التطبيق. وقد نطلب منك التحقق
من هويتك قبل تنفيذ طلبك.</p>

<h2>٨. ملفات تعريف الارتباط (الكوكيز)</h2>
<p>يستخدم موقعنا الإلكتروني وتطبيقاتنا ملفات تعريف الارتباط والتقنيات المماثلة التي يلزمها
عمل الخدمة، مثل إبقائك مسجّل الدخول وتفضيلاتك. ولا نستخدم ملفات كوكيز إعلانية.</p>

<h2>٩. الأطفال</h2>
<p>تستهدف خدماتنا البالغين ومن بلغ السنّ القانوني لإبرام عقد ملزم وفق قوانين بلده. ولا نجمع
معلومات بشكل مقصود من الأطفال دون هذا السن، وإذا اعتقدت أن طفلاً زوّدنا بمعلومات فيرجى
التواصل معنا حتى نحذفها.</p>

<h2>١٠. تعديلات السياسة</h2>
<p>قد نحدّث سياسة الخصوصية هذه من وقت لآخر. وعند إجراء تغيير جوهري، سنُشعرك عبر التطبيق أو
عبر البريد الإلكتروني قبل سريان التغيير. ويظهر دائماً تاريخ آخر تحديث بجوار السياسة
المنشورة.</p>

<h2>١١. تواصل معنا</h2>
<p>إذا كانت لديك أي أسئلة حول سياسة الخصوصية هذه أو حول كيفية تعاملنا مع معلوماتك، فيرجى
التواصل مع فريق الدعم الفني من قسم "المساعدة" في تطبيق "دور".</p>
HTML;
    }
}
