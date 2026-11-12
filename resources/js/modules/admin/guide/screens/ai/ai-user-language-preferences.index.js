/**
 * Guide: AI user language preferences (admin.ai-user-language-preferences.index).
 * Source of truth checked: views/ai-user-language-preferences/index.vue, Modules/AI/routes/admin.php
 * (admin/v1/ai-user-language-preferences), Http/Controllers/AiUserLanguagePreferenceController.php,
 * Http/Requests/AiUserLanguagePreferenceUpdateRequest.php, Repositories/AiUserLanguagePreferenceRepository.php,
 * Models/AiUserLanguagePreference.php, Services/AiChatLanguageResolver.php,
 * Http/Controllers/AiUserLanguagePreferenceSelfController.php, Support/AiAdminScreens.php (READERS).
 */
export default {
    route: 'admin.ai-user-language-preferences.index',
    updated: '2026-11-10',

    ar: {
        summary: 'هنا بتشوف كل يوزر عايز المساعد الذكي يرد عليه بأنهي لغة ولهجة، وتقدر تغيّرها له من الجدول على طول.',
        audience: 'خدمة العملاء أو مسؤول المساعد. فتح الشاشة محتاج صلاحية "تفضيلات لغة اليوزر: عرض"، وتغيير أي قيمة محتاج "تعديل".',
        purpose: [
            'الصف بيتعمل لوحده أول ما اليوزر (أو مقدم الخدمة أو الكابتن) يكلم المساعد، والافتراضي "حسب لغة الرسالة".',
            'اليوزر نفسه يقدر يغيّر تفضيله من صفحة "لغة المساعد" في الويب وAndroid، وانت تقدر تغيّره له من هنا لو طلب من الدعم.',
            'في وضع "حسب لغة الرسالة" المساعد بيرد بنفس لغة ولهجة آخر رسالة (عامية لو كتب عامية، فصحى لو كتب فصحى).',
            'في وضع "لغة ثابتة" المساعد بيرد دايمًا باللغة المختارة، وباللهجة المختارة لو فيه.',
        ],
        sections: [
            {
                title: 'الجدول (الأحدث فوق)',
                items: [
                    { name: 'اليوزر', desc: 'اسم صاحب التفضيل ورقمه.' },
                    { name: 'اللغة المفضلة', desc: 'قايمة بكل اللغات. "بدون تفضيل" يمسح اللغة. بتتحفظ أول ما تختار.' },
                    { name: 'اللهجة المفضلة', desc: 'بتعرض لهجات اللغة المختارة بس (أو كل اللهجات لو مفيش لغة). لو غيّرت اللغة واللهجة القديمة مش تبعها، بتتمسح لوحدها.' },
                    { name: 'اكتشاف تلقائي', desc: 'مفتاح تشغيل وإيقاف بيتحفظ على طول. شوف التنبيهات: الرد حاليًا مش بيقراه.' },
                    { name: 'طريقة الرد', desc: '"حسب لغة الرسالة" أو "لغة ثابتة". دي اللي بتحدد فعلًا المساعد هيرد إزاي.' },
                    { name: 'الترقيم', desc: 'تحت الجدول: تقلب بين الصفحات وتختار عدد الصفوف.' },
                    { name: 'من غير صلاحية "تعديل"', desc: 'كل القوايم والمفاتيح بتظهر مقفولة للقراءة بس.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'خلي المساعد يرد على يوزر بالعامية المصرية دايمًا',
                steps: [
                    'دوّر على صف اليوزر.',
                    'اختار "العربية" في "اللغة المفضلة".',
                    'اختار اللهجة المصرية في "اللهجة المفضلة" (لازم تكون متضافة في شاشة لهجات اللغة).',
                    'غيّر "طريقة الرد" لـ "لغة ثابتة". كل تغيير بيتحفظ لوحده وبيظهر رسالة نجاح.',
                ],
            },
            {
                title: 'رجّع يوزر للوضع العادي',
                steps: ['غيّر "طريقة الرد" لـ "حسب لغة الرسالة".', 'ولو حابب امسح اللغة باختيار "بدون تفضيل".'],
            },
        ],
        affects: [
            { text: 'ردود المساعد الذكي للشخص ده: لغة الرد ولهجته من أول رسالة جاية.' },
            { text: 'تحويل الفويس لنص: اللغة المختارة هنا (أو العربي لو مفيش) بتتبعت كتلميح للتعرف على الكلام.' },
            { text: 'صفحة "لغة المساعد" عند اليوزر بتعرض نفس القيم اللي بتغيّرها هنا.' },
            { route: 'admin.ai-languages.index', text: 'لغات الذكاء الاصطناعي: مصدر قايمة اللغات.' },
            { route: 'admin.ai-language-variants.index', text: 'لهجات اللغة: مصدر قايمة اللهجات وأسلوب كل لهجة.' },
        ],
        warnings: [
            { level: 'warning', text: 'لو اخترت "لغة ثابتة" من غير ما تختار لغة، الشاشة هتقبل، بس المساعد هيفضل يرد "حسب لغة الرسالة". اختار اللغة الأول.' },
            { level: 'warning', text: 'اللهجة بتشتغل في وضع "لغة ثابتة" بس. في "حسب لغة الرسالة" مش بتتقري.' },
            { level: 'info', text: 'قايمة اللغات هنا فيها كل لغات المنصة، حتى اللي مقفولة للمساعد في شاشة لغات الذكاء الاصطناعي.' },
            { level: 'info', text: 'مفيش بحث ولا فلتر. لو التغيير فشل، القيمة بترجع زي ما كانت وبتظهر رسالة الخطأ.' },
        ],
    },

    en: {
        summary: 'See which language and dialect each user wants the AI assistant to reply in, and change it inline from the table.',
        audience: 'Support or assistant owner. Needs "user language preferences: view" to open and "update" to change any value.',
        purpose: [
            'A row is created automatically the first time a user, provider or captain talks to the assistant; the default is "follow input language".',
            'Users change their own preference on the "assistant language" page (web, Android); support can change it here.',
            '"Follow input language": the assistant matches the language and register of the last message (colloquial vs formal).',
            '"Fixed language": always reply in the chosen language, in the chosen variant when set.',
        ],
        sections: [
            {
                title: 'Table (newest first)',
                items: [
                    { name: 'User', desc: 'Owner name and id.' },
                    { name: 'Preferred language', desc: 'All languages; "No preference" clears it. Saved on change.' },
                    { name: 'Preferred variant', desc: 'Variants of the chosen language only (all when none). Cleared automatically when the language changes and it no longer fits.' },
                    { name: 'Auto-detect', desc: 'Switch, saved on change — see warnings: replies do not read it today.' },
                    { name: 'Response mode', desc: '"Follow input language" or "Fixed language" — this is what actually decides the reply.' },
                    { name: 'Pagination', desc: 'Page through and pick rows per page.' },
                    { name: 'Without "update"', desc: 'Every select and switch is disabled (read-only).' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Always reply to a user in Egyptian Arabic',
                steps: ['Find the user row.', 'Pick "Arabic" as preferred language.', 'Pick the Egyptian variant (it must exist on the language variants screen).', 'Set response mode to "Fixed language". Each change saves on its own.'],
            },
            {
                title: 'Put a user back to normal',
                steps: ['Set response mode to "Follow input language".', 'Optionally clear the language with "No preference".'],
            },
        ],
        affects: [
            { text: 'That person’s assistant replies: language and dialect from the next message.' },
            { text: 'Voice-to-text: the chosen language (or Arabic when none) is sent as the transcription hint.' },
            { text: 'The user "assistant language" page shows the same values.' },
            { route: 'admin.ai-languages.index', text: 'AI languages: source of the language list.' },
            { route: 'admin.ai-language-variants.index', text: 'Language variants: source of the variants and their style.' },
        ],
        warnings: [
            { level: 'warning', text: '"Fixed language" with no language is accepted, but the assistant keeps following the input language. Pick the language first.' },
            { level: 'warning', text: 'The variant is used only in "Fixed language" mode.' },
            { level: 'info', text: 'The language list here shows every platform language, even ones switched off for the AI.' },
            { level: 'info', text: 'No search or filter. When a save fails the value reverts and the error shows.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-user-language-preferences/index.vue (inline edit, PUT per change)',
            'Modules/AI/app/Http/Controllers/AiUserLanguagePreferenceController.php',
            'Modules/AI/app/Http/Requests/AiUserLanguagePreferenceUpdateRequest.php',
            'Modules/AI/app/Repositories/AiUserLanguagePreferenceRepository.php (with owner, language, variant; newest first)',
            'Modules/AI/app/Services/AiChatLanguageResolver.php (resolve, preferredLanguageCode)',
            'Modules/AI/app/Http/Controllers/AiUserLanguagePreferenceSelfController.php (user self-service)',
        ],
        api: [
            'GET /api/admin/v1/ai-user-language-preferences',
            'GET|PUT /api/admin/v1/ai-user-language-preferences/{preference} { language_id?, variant_id?, auto_detect?, response_language_mode? }',
            'Options: GET /api/admin/v1/ai-languages?all=1 · GET /api/admin/v1/ai-language-variants?all=1 (readable with this screen’s view)',
            'User: GET|PUT /api/user/v1/ai-language-preference',
        ],
        tables: ['ai_user_language_preferences (owner morph, language_id, variant_id, auto_detect, response_language_mode)', 'languages', 'ai_language_variants'],
        permissions: [
            'ai-user-language-preferences.view (screen, list, read ai-languages and ai-language-variants)',
            'ai-user-language-preferences.update (every inline select and switch)',
        ],
        decisions: [
            {
                ar: 'NEEDS-DECISION: الأدمن يقدر يحفظ "لغة ثابتة" من غير لغة (endpoint اليوزر بيرفضها بـ ai.fixed_mode_requires_language)، ومش بيربط auto_detect بالوضع زي endpoint اليوزر. نوحّد القواعد؟',
                en: 'NEEDS-DECISION: the admin PUT accepts "fixed" with no language (the user endpoint rejects it with ai.fixed_mode_requires_language) and does not tie auto_detect to the mode like the user endpoint. Align the rules?',
            },
            {
                ar: 'NEEDS-DECISION: AiChatLanguageResolver مش بيقرا auto_detect خالص، فمفتاح "اكتشاف تلقائي" مالوش أثر. نشيله من الشاشة ولا نخليه يأثر؟',
                en: 'NEEDS-DECISION: AiChatLanguageResolver never reads auto_detect, so the "Auto-detect" switch has no effect. Remove it from the screen or wire it in?',
            },
        ],
    },
};
