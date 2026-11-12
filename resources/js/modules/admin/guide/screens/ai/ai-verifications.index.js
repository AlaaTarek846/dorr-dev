/**
 * Guide: AI verifications (admin.ai-verifications.index).
 * Source of truth checked: views/ai-verifications/index.vue, Modules/AI/routes/admin.php (ai-verifications),
 * Http/Controllers/AiVerificationController.php, Services/AiVerificationRecordService.php,
 * Repositories/AiVerificationRepository.php, Http/Resources/AiVerificationResource.php, Models/AiVerification.php,
 * Services/AiChatService.php (generateVerifiedReply, pickVerifierProvider, storeVerification),
 * Modules/AI/config/config.php (ai.chat.verification.*).
 */
export default {
    route: 'admin.ai-verifications.index',
    updated: '2026-11-11',

    ar: {
        summary: 'قبل ما إجابة شات دُرر AI توصل لليوزر، النظام بيبعتها لموديل تاني يراجعها (Fact-check). كل صف هنا = مراجعة واحدة: درجة الثقة، ونسبة الكلام المدعوم، والإجابة اتقبلت ولا اتصححت ولا النظام اعتذر.',
        audience: 'المسؤول عن جودة إجابات المساعد. محتاج صلاحية "ai-verifications.view" (عرض بس).',
        purpose: [
            'تعرف إجابات المساعد بتعدي المراجعة بنسبة قد إيه.',
            'تفهم ليه يوزر خد رد "مش متأكد" أو تنبيه "الثقة قليلة" بدل إجابة عادية.',
            'تقارن المزودين: لو مزود إجاباته بتتصحح كتير، ممكن تغيّر التوجيه.',
        ],
        sections: [
            {
                title: 'إزاي المراجعة بتشتغل',
                items: [
                    { name: 'المراجع', desc: 'النظام بيفضّل مزود تاني غير اللي كتب الإجابة. لو مفيش غيره، نفس المزود بيراجع نفسه.' },
                    { name: 'المحاولات', desc: 'لحد محاولتين (الإعداد الافتراضي). لو الإجابة ماعدتش، المساعد بيعيد كتابتها بالملاحظات وبتتراجع تاني.' },
                    { name: 'النجاح', desc: 'درجة الثقة 60% أو أكتر ومفيش أي ملاحظة.' },
                    { name: 'الاعتذار', desc: 'لو في آخر محاولة الثقة أقل من 35%، اليوزر بياخد رد اعتذار بدل الإجابة.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'الطلب', desc: 'رقم الطلب في شاشة "طلبات الذكاء الاصطناعي". طلب واحد ممكن يكون ليه أكتر من صف (صف لكل محاولة).' },
                    { name: 'رقم المحاولة', desc: '1 للمراجعة الأولى، 2 بعد التصحيح.' },
                    { name: 'المدقق', desc: 'المزود اللي عمل المراجعة.' },
                    { name: 'درجة الثقة', desc: 'من 0% لـ 100%، محسوبة من المراجعة نفسها مش من الموديل اللي كتب. "-" لو مفيش.' },
                    { name: 'الادعاءات المدعومة', desc: 'نسبة الجمل اللي المراجع لقاها صح ومدعومة.' },
                    { name: 'الحالة', desc: 'اتقبلت = الإجابة طلعت زي ما هي. محتاجة تصحيح = اتبعتت تتعاد. فشلت = آخر محاولة ماعدتش، فاتبعتت بتنبيه "ثقة قليلة". اعتذر النظام = الثقة قليلة أوي، فاليوزر خد اعتذار. اتخطّت (skipped) = المراجعة نفسها حصل فيها خطأ، فالإجابة طلعت من غير مراجعة.' },
                    { name: 'التاريخ', desc: 'الأحدث فوق.' },
                    { name: 'الترقيم', desc: 'رقم الصفحة وعدد الصفوف. مفيش بحث ولا فلتر.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'يوزر خد رد "مش متأكد" أو تنبيه ثقة قليلة',
                steps: [
                    'هات رقم الطلب من شاشة الطلبات (اسم اليوزر ووقت الرسالة).',
                    'دوّر على الرقم هنا: هتلاقي صف لكل محاولة، وآخر صف حالته "فشلت" أو "اعتذر النظام".',
                    'بص على درجة الثقة والادعاءات المدعومة. لو المشكلة متكررة في نفس الموضوع، زوّد مصادر المعرفة أو غيّر الموديل.',
                ],
            },
            {
                title: 'المراجعة بتتخطى كتير ("اتخطّت")',
                steps: [
                    'لو صفوف كتير "اتخطّت"، يبقى المزود المراجع بيرجع أخطاء.',
                    'افتح "سجل المزودين" وشوف أخطاء المزود اللي في عمود المدقق، وراجع مفتاحه في إعدادات الذكاء الاصطناعي.',
                ],
            },
        ],
        affects: [
            { text: 'الشاشة للقراءة بس. التشغيل والإيقاف والحدود من إعدادات السيرفر (AI_CHAT_VERIFICATION_*) مش من لوحة التحكم.' },
            { route: 'admin.ai-requests.index', text: 'طلبات الذكاء الاصطناعي: الطلب اللي اتراجعت إجابته.' },
            { route: 'admin.ai-responses.index', text: 'ردود الذكاء الاصطناعي: الإجابة النهائية اللي اتحفظت.' },
            { route: 'admin.ai-provider-logs.index', text: 'سجل المزودين: أخطاء المزود المراجع.' },
            { route: 'admin.ai-knowledge-sources.index', text: 'مصادر المعرفة: لو الإجابات بتفشل لقلة المعلومات.' },
        ],
        warnings: [
            { level: 'info', text: 'المراجعة بتشتغل على شات دُرر AI بس، مش على أدوات الـ AI (الترجمة والتلخيص...). ولو المزود فشل في الرد أصلًا مفيش مراجعة.' },
            { level: 'info', text: 'كل مراجعة = نداء AI زيادة للمزود، يعني تكلفة ووقت زيادة على كل رسالة.' },
            { level: 'info', text: 'لما الطلب يتمسح بسياسة الاحتفاظ، المراجعات بتاعته بتتمسح معاه.' },
        ],
    },

    en: {
        summary: 'Before a DORR AI chat answer reaches the user, a second model fact-checks it. One row = one check: confidence, share of supported claims, and whether the answer passed, was corrected, or the system abstained.',
        audience: 'Owner of answer quality. Needs "ai-verifications.view" (view-only).',
        purpose: [
            'See how often assistant answers pass the check.',
            'Explain why a user got an "I’m not sure" reply or a low-confidence notice.',
            'Compare providers: if one is corrected often, change routing.',
        ],
        sections: [
            {
                title: 'How the check works',
                items: [
                    { name: 'Verifier', desc: 'Prefers a different provider from the one that wrote the answer; otherwise the same provider checks itself.' },
                    { name: 'Attempts', desc: 'Up to 2 by default; a failed draft is rewritten with the issues and checked again.' },
                    { name: 'Pass', desc: 'Confidence ≥ 60% and no issues.' },
                    { name: 'Abstain', desc: 'On the last attempt, confidence < 35% → the user gets an abstain reply instead.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Request', desc: 'Request id on AI requests; one row per attempt.' },
                    { name: 'Attempt #', desc: '1 for the first check, 2 after a correction.' },
                    { name: 'Verifier', desc: 'Provider that ran the check.' },
                    { name: 'Confidence', desc: '0–100%, computed by the check, not self-rated; "-" when none.' },
                    { name: 'Supported claims', desc: 'Share of statements the verifier found supported.' },
                    { name: 'Status', desc: 'Passed = sent as is. Needs correction = sent back for a rewrite. Failed = last attempt did not pass, sent with a low-confidence notice. Abstained = very low confidence, user got an abstain reply. Skipped = the check itself errored, the answer went out unchecked.' },
                    { name: 'Date', desc: 'Newest first.' },
                    { name: 'Pagination', desc: 'Page and rows per page. No search or filter.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'A user got "not sure" or a low-confidence notice',
                steps: ['Get the request id from AI requests.', 'Find it here: one row per attempt, the last one Failed or Abstained.', 'Check confidence and supported claims; if a topic keeps failing, add knowledge sources or change the model.'],
            },
            {
                title: 'Many "Skipped" rows',
                steps: ['The verifier provider is returning errors.', 'Open Provider logs for that provider and check its key in AI settings.'],
            },
        ],
        affects: [
            { text: 'Read-only. On/off and thresholds are server settings (AI_CHAT_VERIFICATION_*), not panel settings.' },
            { route: 'admin.ai-requests.index', text: 'AI requests: the checked request.' },
            { route: 'admin.ai-responses.index', text: 'AI responses: the stored final reply.' },
            { route: 'admin.ai-provider-logs.index', text: 'Provider logs: verifier errors.' },
            { route: 'admin.ai-knowledge-sources.index', text: 'Knowledge sources: when answers fail for lack of information.' },
        ],
        warnings: [
            { level: 'info', text: 'Only the DORR AI chat is checked, not AI tools (translate, summary…). No check when the provider failed to answer.' },
            { level: 'info', text: 'Each check is an extra provider call: more cost and time per message.' },
            { level: 'info', text: 'When a request is purged by retention, its verifications go with it.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-verifications/index.vue',
            'Modules/AI/app/Http/Controllers/AiVerificationController.php → Services/AiVerificationRecordService.php',
            'Modules/AI/app/Repositories/AiVerificationRepository.php (with request, verifierProvider)',
            'Modules/AI/app/Http/Resources/AiVerificationResource.php',
            'Writer: Modules/AI/app/Services/AiChatService.php::generateVerifiedReply / storeVerification',
            'Modules/AI/config/config.php → ai.chat.verification (enabled, pass_threshold 0.6, abstain_threshold 0.35, max_attempts 2)',
        ],
        api: ['GET /api/admin/v1/ai-verifications (page, per_page)', 'GET /api/admin/v1/ai-verifications/{verification} (not used by the screen)'],
        tables: ['ai_verifications (request_id → ai_requests cascade, attempt_number, verifier_provider_id, draft_content, claims, issues, supported_claims_ratio, completeness_score, evidence_strength, confidence_score, status)'],
        permissions: ['ai-verifications.view (view-only group)'],
        decisions: [
            { ar: 'الـ API بيرجع المسودة والادعاءات والملاحظات (issues) ودرجة الاكتمال وقوة الدليل، بس الشاشة مفيهاش نافذة تفاصيل، فمتقدرش تعرف المراجع اعترض على إيه.', en: 'The API returns draft, claims, issues, completeness and evidence strength, but the screen has no details modal, so the verifier’s objections cannot be read (NEEDS-DECISION).' },
            { ar: 'draft_content بيتحفظ لحد 20000 حرف بعد minimizedForLog (نفس إخفاء البيانات الشخصية والأسرار بتاع ai_responses). المراجع نفسه بيشوف المسودة كاملة.', en: 'draft_content is stored (up to 20,000 chars) after minimizedForLog (the same personal-data / secret masking as ai_responses); the verifier itself still reads the full draft.' },
            { ar: 'الحالة pending مش بتتكتب أبدًا.', en: 'pending is never written.' },
        ],
    },
};
