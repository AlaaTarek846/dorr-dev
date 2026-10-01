<?php

namespace Database\Seeders\General;

use App\Enums\LegalPageType;
use App\Models\LegalPage;
use Database\Seeders\Concerns\SyncsSeedTranslations;
use Illuminate\Database\Seeder;

class LegalPageSeeder extends Seeder
{
    use SyncsSeedTranslations;

    /**
     * Legal pages are rich text, so the seeded content is the same HTML shape
     * the admin editor stores. Re-running the seeder updates each row in place
     * instead of duplicating it. Exactly two seeds: privacy and term.
     */
    public function run(): void
    {
        foreach ($this->rows() as $row) {
            $page = $this->findExisting($row['type'], $row['content']['en']) ?? new LegalPage;

            $page->fill([
                'type' => $row['type'],
                'service_id' => null,
                'status' => $row['status'],
            ]);
            $page->save();

            $this->syncTranslationFields($page, [
                'en' => ['content' => $row['content']['en']],
                'ar' => ['content' => $row['content']['ar']],
            ]);
        }
    }

    protected function findExisting(string $type, string $englishContent): ?LegalPage
    {
        return LegalPage::query()
            ->where('type', $type)
            ->whereHas('translations', fn ($query) => $query
                ->where('locale', 'en')
                ->where('content', $englishContent))
            ->first();
    }

    /**
     * @return list<array{type: string, status: bool, content: array{en: string, ar: string}}>
     */
    protected function rows(): array
    {
        return [
            [
                'type' => LegalPageType::Privacy->value,
                'status' => true,
                'content' => [
                    'en' => $this->englishPolicy(),
                    'ar' => $this->arabicPolicy(),
                ],
            ],
            [
                'type' => LegalPageType::Term->value,
                'status' => true,
                'content' => [
                    'en' => $this->englishTerms(),
                    'ar' => $this->arabicTerms(),
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
بالسجلات المالية والمعاملات. وعند عدم الحاجة إلى المعلومات، نحذفها أو نجعلها مجهولة الهوية.</p>

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

    protected function englishTerms(): string
    {
        return <<<'HTML'
<h2>1. Agreement</h2>
<p>These terms of service form a binding agreement between you and Dorr. By creating an
account, booking a service or using the platform in any way, you accept these terms in
full. If you do not agree with any part of them, please do not use our services.</p>

<h2>2. Accounts</h2>
<p>You must provide accurate and complete information when creating an account and keep
it up to date. You are responsible for safeguarding your account credentials and for
everything that happens under your account. Notify us immediately if you suspect
unauthorised use of your account.</p>
<ul>
<li>Use a strong, unique password for your Dorr account.</li>
<li>Never share your wallet PIN or OTP codes with anyone.</li>
<li>Keep your phone number and email address up to date.</li>
</ul>

<h2>3. Using the platform</h2>
<p>You agree to use the platform only for lawful purposes and in a way that does not
infringe the rights of others. You may not misrepresent your identity, submit false
information, or attempt to disrupt the platform, its users or its providers.</p>

<h2>4. Bookings and payments</h2>
<p>When you book a service, a contract is formed directly between you and the provider
who accepts the booking. Dorr facilitates the transaction and may hold or process
payments on the provider's behalf. Prices are shown before you confirm a booking and
may include service fees.</p>

<h2>5. Cancellations</h2>
<p>Cancellation policies are set per service category and shown at the time of booking.
A provider may cancel a booking when they cannot complete it; we will issue a refund in
that case and help you rebook.</p>

<h2>6. Wallet and transfers</h2>
<p>Your wallet balance is used for bookings and transfers. You must protect your wallet
PIN and treat transfer confirmations like cash. We may limit, hold or reverse transactions
to investigate fraud, chargebacks or disputes.</p>

<h2>7. Acceptable content</h2>
<p>You are responsible for any content you post, upload or share, and you must not post
content that is unlawful, defamatory, obscene, harassing, or that violates the rights of
others or our policies.</p>

<h2>8. Suspension and termination</h2>
<p>We may suspend or close your account if you breach these terms, act fraudulently, or
harm the platform or other users. You may delete your account at any time from the app;
deleting your account does not affect obligations that survive termination.</p>

<h2>9. Liability</h2>
<p>The platform is provided on an "as available" basis. To the maximum extent permitted
by law, Dorr is not liable for indirect or incidental damages arising from your use of
the service, and each party's total liability is limited to the amounts you paid in the
six months before the claim.</p>

<h2>10. Changes to these terms</h2>
<p>We may update these terms from time to time and will notify you of material changes
through the app or by email. Continued use of the platform after the changes take effect
means you accept the updated terms.</p>

<h2>11. Contact us</h2>
<p>If you have any questions about these terms, please contact our support team from the
Help section of the Dorr app.</p>
HTML;
    }

    protected function arabicTerms(): string
    {
        return <<<'HTML'
<h2>١. الاتفاقية</h2>
<p>تشكّل هذه الشروط اتفاقية ملزمة بينك وبين منصة "دور". بإنشائك حساباً أو حجز خدمة أو
استخدامك المنصة بأي شكل، فإنك تقبل هذه الشروط بالكامل. وإذا كنت لا توافق على أي جزء
منها، فيرجى عدم استخدام خدماتنا.</p>

<h2>٢. الحسابات</h2>
<p>يجب عليك تقديم معلومات دقيقة وكاملة عند إنشاء الحساب وإبقاؤها محدّثة. أنت مسؤول عن
حماية بيانات تسجيل الدخول الخاصة بك وعن كل ما يحدث من حسابك. أبلغنا فوراً إذا اشتبهت
في أي استخدام غير مصرّح به لحسابك.</p>
<ul>
<li>استخدم كلمة مرور قوية وفريدة لحسابك في "دور".</li>
<li>لا تشارك الرقم السري لمحفظتك أو رموز التحقق مع أي شخص.</li>
<li>حافظ على تحديث رقم جوالك وبريدك الإلكتروني.</li>
</ul>

<h2>٣. استخدام المنصة</h2>
<p>تتعهد باستخدام المنصة للأغراض المشروعة فقط وبطريقة لا تنتهك حقوق الآخرين. لا يجوز لك
انتحال شخصية غيرك أو تقديم معلومات كاذبة أو محاولة التعطيل عن المنصة أو مستخدميها أو
مزوديها.</p>

<h2>٤. الحجوزات والمدفوعات</h2>
<p>عند حجز خدمة، يُبرم تعاقد مباشر بينك وبين المزود الذي يقبل الحجز. وتقوم "دور" بتيسير
المعاملة وقد تحتفظ بالمدفوعات أو تعالجها نيابة عن المزود. تُعرض الأسعار قبل تأكيد الحجز،
وقد تشمل رسوم الخدمة.</p>

<h2>٥. الإلغاءات</h2>
<p>تُحدَّد سياسات الإلغاء لكل فئة خدمة وتظهر وقت الحجز. قد يلغي المزود الحجز إذا تعذّر
إتمامه، وفي هذه الحالة نُصدر استرداداً ونساعدك في إعادة الحجز.</p>

<h2>٦. المحفظة والتحويلات</h2>
<p>تُستخدم رصيد محفظتك في الحجوزات والتحويلات. عليك حماية الرقم السري لمحفظتك والتعامل
مع تأكيدات التحويل كما تتعامل مع النقد. قد نقوم بتقييد أو تعليق أو إعادة المعاملات
للتحقيق في الاحتيال أو الاعتراضات أو النزاعات.</p>

<h2>٧. المحتوى المقبول</h2>
<p>أنت مسؤول عن أي محتوى تنشره أو ترفعه أو تشاركه، ويجب ألا تنشر محتوى غير قانوني أو
تشهيرياً أو فاحشاً أو مضايقاً أو منتهكاً لحقوق الآخرين أو لسياساتنا.</p>

<h2>٨. التعلّيق والإنهاء</h2>
<p>قد نعلّق حسابك أو نغلقه إذا خالفت هذه الشروط أو تصرّفت باحتيال أو أضررت بالمنصة أو
بالمستخدمين. يمكنك حذف حسابك في أي وقت من التطبيق، ولا يؤثر حذف الحساب على الالتزامات
التي تبقى سارية بعد الإنهاء.</p>

<h2>٩. المسؤولية</h2>
<p>تُقدَّم المنصة على أساس "كما هي". إلى أقصى حد يسمح به القانون، لا تتحمل "دور"
المسؤولية عن الأضرار غير المباشرة أو العرضية الناشئة عن استخدامك للخدمة، وتقتصر
المسؤولية الإجمالية لكل طرف على المبالغ التي دفعتها خلال الأشهر الستة السابقة للمطالبة.</p>

<h2>١٠. تعديل الشروط</h2>
<p>قد نحدّث هذه الشروط من وقت لآخر وسنُشعرك بالتغييرات الجوهرية عبر التطبيق أو البريد
الإلكتروني. واستمرارك في استخدام المنصة بعد سريان التغييرات يعني قبولك للشروط
المحدّثة.</p>

<h2>١١. تواصل معنا</h2>
<p>إذا كانت لديك أي أسئلة حول هذه الشروط، فيرجى التواصل مع فريق الدعم الفني من قسم
"المساعدة" في تطبيق "دور".</p>
HTML;
    }
}