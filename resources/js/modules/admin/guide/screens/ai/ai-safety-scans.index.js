/**
 * Guide: AI safety scans (admin.ai-safety-scans.index).
 * Source of truth checked: views/ai-safety-scans/index.vue (list + findings modal), Modules/AI/routes/admin.php
 * (ai-safety-scans), Http/Controllers/AiSafetyScanController.php, Services/AiSafetyScanService.php,
 * Repositories/AiSafetyScanRepository.php, Http/Resources/AiSafetyScanResource.php, Models/AiSafetyScan.php,
 * migration 2026_09_22_120300_create_ai_safety_scans_table.php; writer Services/Monitoring/AiSafetyScanRecorder.php,
 * called from Services/AiChatService.php (sendMessage) and Services/AiTaskRunner.php (chat, image);
 * Services/AiSafetyGuard.php (the check itself); Console/Commands/EnforceAiDataRetention.php (purge).
 */
export default {
    route: 'admin.ai-safety-scans.index',
    updated: '2026-11-11',

    ar: {
        summary: 'سجل كل فحص أمان بيتعمل على رسالة الشخص قبل ما تروح للموديل: عدّت، ولا اتمنعت، ولا اتنضفت، ولا محتاجة مراجعة. صف لكل رسالة، حتى اللي عدّت.',
        audience: 'مسؤول الأمان. محتاج صلاحية "ai-safety-scans.view" (عرض بس).',
        purpose: [
            'تشوف قواعد الأمان بتشتغل على كام رسالة وبتاخد إيه قرار، مش بس المرات اللي اتمنع فيها حاجة.',
            'كل رسالة في شات DORR AI وكل رسالة أو وصف صورة في أدوات الذكاء الاصطناعي في باقي الموديولات بتتفحص بقواعد الأمان (AiSafetyGuard) وبيتكتب لها صف هنا.',
            'المرات اللي قاعدة اتطبقت فيها فعلًا (منع / تنضيف / مراجعة) بتتسجل كمان في "أحداث الأمان" مع سبب مختصر.',
        ],
        sections: [
            {
                title: 'الجدول',
                items: [
                    { name: 'صاحب المحتوى', desc: 'صاحب الرسالة اللي اتفحصت (الاسم، ولو مفيش اسم رقم التليفون)، وتحته رقمه.' },
                    { name: 'نوع الهدف', desc: 'دلوقتي دايمًا input = رسالة الشخص. فحص الرد (output) أو الملفات مش موجود في النظام لسه.' },
                    { name: 'نوع الفحص', desc: 'دلوقتي دايمًا harm: قواعد الكلمات المفتاحية ودرجة الخطر. الأنواع التانية (injection، malware، secret...) مكانها محجوز بس مفيش فحص بيكتبها.' },
                    { name: 'القرار', desc: 'passed = مفيش قاعدة اتطبقت، blocked = اتمنعت، sanitized = الكلمات اتغطّت بنجوم، review_required = قاعدة "مراجعة" أو "طلب تأكيد" اتطبقت.' },
                    { name: 'التاريخ', desc: 'الأحدث فوق.' },
                    { name: '👁 التفاصيل', desc: 'نافذة بتعرض القرار و"النتائج التفصيلية" (findings): رقم القاعدة ورقم السياسة ونوع الشرط والإجراء. نص الرسالة نفسه مش بيتخزن هنا خالص. للقراءة بس.' },
                    { name: 'الترقيم', desc: 'رقم الصفحة وعدد الصفوف.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'معرفة ليه رسالة اتمنعت',
                steps: [
                    'دوّر على صاحب الرسالة ووقت المشكلة، والقرار blocked.',
                    'دوس 👁 واقرا رقم القاعدة في النتائج التفصيلية.',
                    'افتح "قواعد الأمان" وشوف القاعدة دي، أو "أحداث الأمان" علشان السبب المختصر.',
                ],
            },
            {
                title: 'التأكد إن قاعدة جديدة مش بتمنع كلام عادي',
                steps: [
                    'بعد ما تفعّل القاعدة، تابع القرارات هنا لكام ساعة.',
                    'لو لقيت blocked كتير برقم القاعدة دي على رسايل عادية، عدّل كلماتها أو خليها "مراجعة" بدل "منع".',
                ],
            },
        ],
        affects: [
            { text: 'الشاشة للقراءة بس.' },
            { route: 'admin.ai-safety-rules.index', text: 'قواعد الأمان: هي اللي بتحدد القرار المسجل هنا.' },
            { route: 'admin.ai-safety-events.index', text: 'أحداث الأمان: المرات اللي قاعدة اتطبقت فيها، مع سبب مختصر.' },
            { route: 'admin.ai-requests.index', text: 'طلبات الذكاء الاصطناعي: فحص شات DORR AI مربوط برقم الطلب (أدوات الموديولات التانية من غير رقم طلب لو اتمنعت).' },
            { route: 'admin.ai-data-policies.index', text: 'سياسات البيانات: مدة الاحتفاظ بتمسح الصفوف القديمة (ai:enforce-retention).' },
        ],
        warnings: [
            { level: 'info', text: 'الجدول بيكبر بسرعة (صف لكل رسالة)، وبيتمسح القديم منه يوميًا حسب مدة الاحتفاظ في سياسة البيانات الشخصية.' },
            { level: 'info', text: 'النتائج التفصيلية بيانات داخلية، متتبعتش لليوزر، ومفيهاش نص الرسالة.' },
        ],
    },

    en: {
        summary: 'Log of every safety check run on a person\'s message before it goes to a model: passed, blocked, sanitized or needs review. One row per message, including the ones that passed.',
        audience: 'Security owner. Needs "ai-safety-scans.view" (view-only).',
        purpose: [
            'See how many messages the safety rules check and what they decide — not only the times something was stopped.',
            'Every DORR AI chat message, and every message or image prompt sent through the AI tools of other modules, is checked by the safety rules (AiSafetyGuard) and gets a row here.',
            'The times a rule actually applied (block / sanitize / review) are also logged on "Safety events" with a short reason.',
        ],
        sections: [
            {
                title: 'Table',
                items: [
                    { name: 'Content owner', desc: 'Who sent the message (name, or phone when there is no name), id under it.' },
                    { name: 'Target type', desc: 'Today always input = the person\'s message. Output or file scanning does not exist yet.' },
                    { name: 'Scan type', desc: 'Today always harm: keyword and risk-score rules. The other types (injection, malware, secret…) are reserved; nothing writes them.' },
                    { name: 'Decision', desc: 'passed = no rule applied, blocked, sanitized = the words were starred out, review_required = a "review" or "require confirmation" rule applied.' },
                    { name: 'Date', desc: 'Newest first.' },
                    { name: '👁 Details', desc: 'Modal with the decision and the "findings": rule id, policy id, condition type and action. The message text itself is never stored here. Read-only.' },
                    { name: 'Pagination', desc: 'Page and rows per page.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Why was a message blocked',
                steps: ['Find the owner, the time and decision = blocked.', 'Click 👁 and read the rule id in the findings.', 'Open "Safety rules" for that rule, or "Safety events" for the short reason.'],
            },
            {
                title: 'Check a new rule does not block ordinary messages',
                steps: ['After switching the rule on, watch the decisions here for a few hours.', 'Many "blocked" rows with that rule id on ordinary messages → adjust its keywords or make it "review" instead of "block".'],
            },
        ],
        affects: [
            { text: 'Read-only.' },
            { route: 'admin.ai-safety-rules.index', text: 'Safety rules: they decide what is logged here.' },
            { route: 'admin.ai-safety-events.index', text: 'Safety events: the times a rule applied, with a short reason.' },
            { route: 'admin.ai-requests.index', text: 'AI requests: a DORR AI chat scan points at its request (other modules\' tools have none when blocked).' },
            { route: 'admin.ai-data-policies.index', text: 'Data policies: the retention window purges old rows (ai:enforce-retention).' },
        ],
        warnings: [
            { level: 'info', text: 'The table grows fast (one row per message); old rows are purged daily after the personal-data policy\'s retention window.' },
            { level: 'info', text: 'Findings are internal data, never sent to the user, and hold no message text.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-safety-scans/index.vue (details modal renders findings)',
            'Modules/AI/app/Http/Controllers/AiSafetyScanController.php → Services/AiSafetyScanService.php',
            'Modules/AI/app/Repositories/AiSafetyScanRepository.php (with owner)',
            'Modules/AI/app/Http/Resources/AiSafetyScanResource.php (owner.phone on the admin API only), Models/AiSafetyScan.php',
            'Writer: Modules/AI/app/Services/Monitoring/AiSafetyScanRecorder.php ← AiChatService::sendMessage(), AiTaskRunner::chat()/image()',
            'Modules/AI/app/Console/Commands/EnforceAiDataRetention.php (purges this table)',
        ],
        api: ['GET /api/admin/v1/ai-safety-scans (page, per_page)', 'GET /api/admin/v1/ai-safety-scans/{safetyScan} (not used by the screen)'],
        tables: ['ai_safety_scans (request_id, owner morph, target_type, scan_type, decision, findings JSON {rule_id, policy_id, condition_type, action})'],
        permissions: ['ai-safety-scans.view (view-only group)'],
        decisions: [
            { ar: 'الفحص الوحيد الموجود هو AiSafetyGuard على نص الشخص، فبيتكتب target_type=input وscan_type=harm. فحص الرد أو الملفات أو حقن الأوامر محتاج فحص حقيقي يتبني الأول.', en: 'The only check that exists is AiSafetyGuard on the person\'s text, so rows are target_type=input, scan_type=harm. Output / file / injection scanning needs a real scanner first.' },
        ],
    },
};
