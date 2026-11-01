<?php

namespace Modules\Freelance\Database\Seeders;

use App\Enums\LegalPageType;
use App\Models\LegalPage;
use App\Models\ServiceCategory;
use Database\Seeders\Concerns\SyncsSeedTranslations;
use Illuminate\Database\Seeder;

/**
 * The privacy policy and terms of the Freelance service (`legal_pages.service_id` = the `freelance` service category),
 * shown by the freelancer app (`GET freelance/v1/legal-pages`). Edited later on the dashboard's Legal pages screen.
 *
 * Safe to run again: a page is matched by its type and service, so an existing one keeps what the admin wrote.
 */
class FreelanceLegalPageSeeder extends Seeder
{
    use SyncsSeedTranslations;

    public function run(): void
    {
        $serviceId = ServiceCategory::query()
            ->where('module_name', config('freelance.service_module_name', 'freelance'))
            ->value('id');

        if ($serviceId === null) {
            return;
        }

        foreach ($this->rows() as $row) {
            if (LegalPage::query()->where('type', $row['type'])->where('service_id', $serviceId)->exists()) {
                continue;
            }

            $page = LegalPage::query()->create(['type' => $row['type'], 'service_id' => $serviceId, 'status' => true]);

            $this->syncTranslationFields($page, [
                'en' => ['content' => $row['en']],
                'ar' => ['content' => $row['ar']],
            ]);
        }
    }

    /**
     * @return list<array{type: string, en: string, ar: string}>
     */
    private function rows(): array
    {
        return [
            ['type' => LegalPageType::Privacy->value, 'en' => $this->englishPolicy(), 'ar' => $this->arabicPolicy()],
            ['type' => LegalPageType::Term->value, 'en' => $this->englishTerms(), 'ar' => $this->arabicTerms()],
        ];
    }

    private function englishPolicy(): string
    {
        return <<<'HTML'
<h2>1. Introduction</h2>
<p>This privacy policy explains how Dorr collects, uses and protects your information when you work as a freelancer on the Dorr Freelance service. By creating a freelancer account you agree to the practices described below.</p>

<h2>2. Information we collect</h2>
<ul>
<li><strong>Account and profile</strong> — your name, phone number, photo, country, specialties, skills, bio and hourly rate.</li>
<li><strong>Verification documents</strong> — identity and professional documents you upload so the team can verify you.</li>
<li><strong>Portfolio</strong> — the works, images and links you choose to publish.</li>
<li><strong>Work data</strong> — offers, agreements, milestones, deliveries, progress reports, reviews and disputes.</li>
<li><strong>Messages</strong> — the project chat with clients and your conversations with support.</li>
<li><strong>Payments</strong> — wallet balance, earnings, withdrawals and invoices.</li>
<li><strong>Device data</strong> — device identifiers, app version, IP address and notification token.</li>
</ul>

<h2>3. How we use it</h2>
<ul>
<li>To verify you and show your profile and portfolio to clients.</li>
<li>To match you with projects and let you send offers.</li>
<li>To hold client payments safely, release milestones and pay out your earnings.</li>
<li>To resolve disputes and keep the platform safe from fraud and abuse.</li>
<li>To send you project updates, messages and support replies.</li>
</ul>

<h2>4. What clients and others can see</h2>
<p>Clients can see your public profile, verified badge, specialties, skills, portfolio and reviews. Your phone number, identity documents and earnings are never shown to clients.</p>

<h2>5. Sharing</h2>
<p>We do not sell your information. We share it only with payment and messaging providers needed to run the service, and with authorities where the law requires it.</p>

<h2>6. Retention and security</h2>
<p>We keep your data while your account is active and for the period the law requires for financial records. Data is encrypted in transit and access is limited to authorised staff.</p>

<h2>7. Your rights</h2>
<p>You may ask to see, correct or delete your data, or delete your account, from the app or through Contact us. Some records must be kept where the law requires it.</p>

<h2>8. Changes and contact</h2>
<p>We will tell you in the app before a material change takes effect. For any question, contact our support team from Account &gt; Support.</p>
HTML;
    }

    private function arabicPolicy(): string
    {
        return <<<'HTML'
<h2>١. مقدمة</h2>
<p>توضّح سياسة الخصوصية هذه كيف تجمع منصة "دور" معلوماتك وتستخدمها وتحميها عند عملك كمستقل في خدمة العمل الحر. بإنشائك حساب مستقل فإنك توافق على الممارسات الموضحة أدناه.</p>

<h2>٢. المعلومات التي نجمعها</h2>
<ul>
<li><strong>الحساب والملف الشخصي</strong> — اسمك ورقم جوالك وصورتك وبلدك وتخصصاتك ومهاراتك ونبذتك وسعر ساعتك.</li>
<li><strong>مستندات التوثيق</strong> — مستندات الهوية والمستندات المهنية التي ترفعها ليتحقق منك الفريق.</li>
<li><strong>معرض الأعمال</strong> — الأعمال والصور والروابط التي تختار نشرها.</li>
<li><strong>بيانات العمل</strong> — العروض والاتفاقيات والمراحل والتسليمات وتقارير التقدم والتقييمات والنزاعات.</li>
<li><strong>الرسائل</strong> — محادثة المشروع مع العملاء ومحادثاتك مع الدعم.</li>
<li><strong>المدفوعات</strong> — رصيد المحفظة والأرباح وعمليات السحب والفواتير.</li>
<li><strong>بيانات الجهاز</strong> — معرّفات الجهاز وإصدار التطبيق وعنوان IP ورمز الإشعارات.</li>
</ul>

<h2>٣. كيف نستخدمها</h2>
<ul>
<li>للتحقق منك وعرض ملفك ومعرض أعمالك على العملاء.</li>
<li>لمطابقتك مع المشاريع وتمكينك من إرسال العروض.</li>
<li>لحفظ مدفوعات العملاء بأمان وصرف المراحل وتحويل أرباحك.</li>
<li>لحل النزاعات وحماية المنصة من الاحتيال وإساءة الاستخدام.</li>
<li>لإرسال تحديثات المشاريع والرسائل وردود الدعم.</li>
</ul>

<h2>٤. ما يراه العملاء وغيرهم</h2>
<p>يرى العملاء ملفك العام وشارة التوثيق وتخصصاتك ومهاراتك ومعرض أعمالك وتقييماتك. أما رقم جوالك ومستندات هويتك وأرباحك فلا تُعرض للعملاء أبداً.</p>

<h2>٥. المشاركة</h2>
<p>لا نبيع معلوماتك. نشاركها فقط مع مزودي الدفع والرسائل اللازمين لتشغيل الخدمة، ومع الجهات الرسمية عندما يوجب القانون ذلك.</p>

<h2>٦. الاحتفاظ والأمان</h2>
<p>نحتفظ ببياناتك طوال نشاط حسابك ولمدة يفرضها القانون للسجلات المالية. تُشفَّر البيانات أثناء النقل ويقتصر الوصول إليها على الموظفين المصرّح لهم.</p>

<h2>٧. حقوقك</h2>
<p>يمكنك طلب الاطلاع على بياناتك أو تصحيحها أو حذفها أو حذف حسابك من التطبيق أو عبر "تواصل معنا". وقد نحتفظ ببعض السجلات حين يوجب القانون ذلك.</p>

<h2>٨. التعديلات والتواصل</h2>
<p>سنُخطرك داخل التطبيق قبل سريان أي تغيير جوهري. لأي استفسار تواصل مع فريق الدعم من حساب &gt; الدعم.</p>
HTML;
    }

    private function englishTerms(): string
    {
        return <<<'HTML'
<h2>1. Agreement</h2>
<p>These terms are a binding agreement between you and Dorr for using the Freelance service as a freelancer. By creating an account you accept them in full.</p>

<h2>2. Your account</h2>
<ul>
<li>You must be an adult, give accurate information and keep it up to date.</li>
<li>Your account is personal: do not share it or your verification codes.</li>
<li>You must pass verification before you can send offers.</li>
</ul>

<h2>3. Offers and agreements</h2>
<p>An agreement is formed when a client accepts your offer. You agree to deliver the work as described, in the milestones and by the dates agreed. Changes to scope, price or dates must be agreed through the app.</p>

<h2>4. Payments and fees</h2>
<p>The client funds each milestone, and Dorr holds the amount until the client approves your delivery or the review period ends. The platform commission is shown before you send an offer and is deducted from your earnings. Earnings are paid to your wallet and can be withdrawn by the methods offered in the app.</p>

<h2>5. Delivery and reviews</h2>
<p>Submit each milestone with clear files or links and keep progress reports up to date. A client may approve it, ask for changes or open a dispute. Reviews must be honest.</p>

<h2>6. Conduct</h2>
<ul>
<li>Do not move the client off the platform to avoid fees, or take payment outside it.</li>
<li>Do not share contact details in chat before an agreement exists.</li>
<li>Do not submit work you do not own, copied work, or unlawful content.</li>
<li>Be respectful in messages and meetings.</li>
</ul>

<h2>7. Disputes</h2>
<p>If you and a client disagree, either side may open a dispute. Dorr reviews the evidence in the project and its decision on the held amount is final within the platform.</p>

<h2>8. Suspension and termination</h2>
<p>We may warn, restrict or close an account that breaks these terms, commits fraud, or harms clients or the platform. You may delete your account at any time once your open projects are finished.</p>

<h2>9. Liability</h2>
<p>Dorr is a platform that connects you with clients and is not a party to the work itself. To the extent the law allows, Dorr is not liable for indirect losses arising from projects.</p>

<h2>10. Changes and contact</h2>
<p>We may update these terms and will tell you in the app. Continued use means you accept them. For questions, contact support from Account &gt; Support.</p>
HTML;
    }

    private function arabicTerms(): string
    {
        return <<<'HTML'
<h2>١. الاتفاقية</h2>
<p>تشكّل هذه الشروط اتفاقية ملزمة بينك وبين "دور" لاستخدام خدمة العمل الحر كمستقل. بإنشائك حساباً فإنك تقبلها بالكامل.</p>

<h2>٢. حسابك</h2>
<ul>
<li>يجب أن تكون بالغاً وأن تقدّم معلومات دقيقة وتبقيها محدّثة.</li>
<li>حسابك شخصي: لا تشاركه ولا تشارك رموز التحقق.</li>
<li>يجب اجتياز التوثيق قبل أن تتمكن من إرسال العروض.</li>
</ul>

<h2>٣. العروض والاتفاقيات</h2>
<p>تنعقد الاتفاقية عندما يقبل العميل عرضك. تلتزم بتسليم العمل كما هو موصوف، وفق المراحل والمواعيد المتفق عليها. أي تغيير في النطاق أو السعر أو المواعيد يتم الاتفاق عليه عبر التطبيق.</p>

<h2>٤. المدفوعات والعمولة</h2>
<p>يموّل العميل كل مرحلة وتحتفظ "دور" بالمبلغ حتى يعتمد العميل تسليمك أو تنتهي مدة المراجعة. تظهر عمولة المنصة قبل إرسال العرض وتُخصم من أرباحك. تُحوَّل الأرباح إلى محفظتك ويمكن سحبها بالطرق المتاحة في التطبيق.</p>

<h2>٥. التسليم والتقييم</h2>
<p>سلّم كل مرحلة بملفات أو روابط واضحة وأبقِ تقارير التقدم محدّثة. يمكن للعميل اعتماد التسليم أو طلب تعديلات أو فتح نزاع. يجب أن تكون التقييمات صادقة.</p>

<h2>٦. السلوك</h2>
<ul>
<li>لا تنقل العميل خارج المنصة لتجنب العمولة ولا تقبل دفعاً خارجها.</li>
<li>لا تشارك بيانات التواصل في المحادثة قبل وجود اتفاقية.</li>
<li>لا تسلّم أعمالاً لا تملكها أو منسوخة أو محتوى مخالفاً للقانون.</li>
<li>التزم بالاحترام في الرسائل والاجتماعات.</li>
</ul>

<h2>٧. النزاعات</h2>
<p>إذا اختلفت مع عميل يحق لأي طرف فتح نزاع. تراجع "دور" الأدلة داخل المشروع، ويكون قرارها في المبلغ المحتجز نهائياً داخل المنصة.</p>

<h2>٨. التعليق والإنهاء</h2>
<p>قد ننبّه الحساب المخالف أو نقيّده أو نغلقه عند مخالفة الشروط أو الاحتيال أو الإضرار بالعملاء أو المنصة. يمكنك حذف حسابك في أي وقت بعد انتهاء مشاريعك المفتوحة.</p>

<h2>٩. المسؤولية</h2>
<p>"دور" منصة تربطك بالعملاء وليست طرفاً في العمل نفسه. وبالقدر الذي يسمح به القانون لا تتحمل "دور" الخسائر غير المباشرة الناتجة عن المشاريع.</p>

<h2>١٠. التعديلات والتواصل</h2>
<p>قد نحدّث هذه الشروط وسنُخطرك داخل التطبيق، واستمرارك في الاستخدام يعني قبولك لها. للاستفسار تواصل مع الدعم من حساب &gt; الدعم.</p>
HTML;
    }
}
