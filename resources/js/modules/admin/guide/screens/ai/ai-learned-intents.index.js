/**
 * Guide: learned phrases (admin.ai-learned-intents.index) - the smart intent router's learned dictionary.
 * Source of truth checked: views/ai-learned-intents/*.vue, composables/useAiRoutingScreens.js (useAiLearnedIntents),
 * Modules/AI/app/Models/AiLearnedIntent.php (+ Concerns/HasIsActiveAlias.php), Repositories/AiLearnedIntentRepository.php,
 * Services/AiLearnedIntentAdminService.php, Services/AiLearnedIntentStore.php, Services/AiIntentRouterService.php,
 * Http/Controllers/AiLearnedIntentController.php, Http/Requests/AiLearnedIntentRequest.php,
 * Http/Resources/AiLearnedIntentResource.php, config ai.intent_router.*, routes/admin.php.
 */
export default {
    route: 'admin.ai-learned-intents.index',
    updated: '2026-11-10',

    ar: {
        summary: 'هنا العبارات اللي الراوتر الذكي اتعلّمها: لما رسالة مكتوبة بصياغة القاموس الثابت مش عارفها (مثلًا "نفسي أشوف..." = اعمل صورة)، الموديل الافتراضي بيتسأل مرة، ولو كان واثق العبارة بتتحفظ هنا عشان المرة الجاية تتفهم فورًا ومن غير تكلفة.',
        audience: 'مسؤول الذكاء الاصطناعي. محتاج صلاحيات "العبارات المتعلَّمة": عرض، إضافة، تعديل، حذف، تغيير الحالة، حذف متعدد.',
        purpose: [
            'نوعين: "الرسالة كلها" (exact) بتشتغل على طول، و"عبارة جوه الرسالة" (phrase) بتشتغل بعد ما الموديل يأكدها كذا مرة (الرقم في كارت "مستنية تأكيد").',
            'لو الموديل رجّع نية مختلفة لنفس العبارة، بتتقفل وتتعلّم "تحتاج مراجعة"، والراوتر عمره ما يرجّع يشغّلها لوحده.',
            'أي تغيير هنا (تشغيل، إيقاف، حذف، ترجيع) بيسري على الرسالة الجاية على طول.',
        ],
        sections: [
            {
                title: 'الكروت',
                items: [
                    { name: 'إجمالي / شغّالة / مستنية تأكيد / تحتاج مراجعة', desc: 'من غير اللي في السلة.' },
                    { name: 'مرات توفير الموديل', desc: 'كام مرة عبارة متعلّمة ردّت بدل ما يتسأل الموديل.' },
                ],
            },
            {
                title: 'جرّب عبارة',
                items: [
                    { name: 'جرّب', desc: 'بيوريك الشات هيعمل إيه مع رسالة: القاموس، عبارة متعلّمة، هيسأل الموديل، أو محادثة عادية. مفيش حاجة بتتبعت أو تتحسب أو تتعلّم. محتاج صلاحية "تعديل".' },
                ],
            },
            {
                title: 'شريط الأدوات والفلاتر',
                items: [
                    { name: 'البحث', desc: 'في العبارة، والكتابة بتتظبط زي العبارات المحفوظة (ى/ي، ة/ه، التشكيل).' },
                    { name: 'الكل / شغّالة / مقفولة / المحذوفات', desc: 'فلترة سريعة بالأعداد (حسب الفلاتر التانية كمان).' },
                    { name: 'حالة الراوتر / النية / نوع المطابقة / المصدر', desc: 'فلاتر الشاشة: مستنية تأكيد أو تحتاج مراجعة، ونوع الطلب، والمطابقة، واتعلّمت من الموديل ولا اتضافت من الأدمن.' },
                    { name: 'حذف المحدد / إضافة عبارة', desc: 'حسب صلاحياتك.' },
                ],
            },
            {
                title: 'الجدول',
                items: [
                    { name: 'العبارة', desc: 'وتحتها نوع المطابقة واللغة والرقم. دوس عليها يفتح التعديل.' },
                    { name: 'النية', desc: 'نوع الطلب (صورة، صوت، ملف...) وصيغة الملف لو فيه.' },
                    { name: 'حالة الراوتر', desc: 'شغّالة / مستنية تأكيد (كام تأكيد من كام) / تحتاج مراجعة (كام مرة اتناقضت).' },
                    { name: 'الثقة / الاستخدام / المصدر', desc: 'أعلى ثقة سجّلها الموديل، وكام مرة اتستخدمت وآخر مرة، ومين ضافها.' },
                    { name: 'الحالة', desc: 'تشغيل وإيقاف (صلاحية "تغيير الحالة"). الإيقاف بيعلّمها "تحتاج مراجعة"، والتشغيل بيمسح ده.' },
                ],
            },
            {
                title: 'فورم العبارة',
                items: [
                    { name: 'العبارة', desc: 'من 2 لـ 120 حرف، بتتحفظ بعد ظبط الكتابة. مينفعش تتكرر بنفس النية والمطابقة (حتى في السلة). بتتكتب مرة واحدة ومش بتتغير.' },
                    { name: 'نوع المطابقة', desc: 'عبارة جوه الرسالة أو الرسالة كلها. مرة واحدة وقت الإضافة.' },
                    { name: 'النية', desc: 'واحدة من أنواع الطلبات. لو غيّرتها لنية موجودة لنفس العبارة هتترفض.' },
                    { name: 'صيغة الملف', desc: 'لنية "ملف" بس: PDF أو Word أو Excel أو تلقائي.' },
                    { name: 'الحالة', desc: 'عبارة الأدمن بتشتغل على طول (ثقة 100%). لو حفظتها مقفولة بتتعلّم "تحتاج مراجعة".' },
                ],
            },
        ],
        tasks: [
            {
                title: 'تعليم الشات صياغة جديدة',
                steps: ['دوس "إضافة عبارة".', 'اكتب العبارة واختار نوع المطابقة والنية.', 'دوس حفظ - بتشتغل من الرسالة الجاية.'],
            },
            {
                title: 'مراجعة عبارة اتناقضت',
                steps: ['اختار "تحتاج مراجعة" من فلتر حالة الراوتر.', 'لو النية صح شغّلها، لو غلط غيّر النية أو انقلها للسلة.'],
            },
        ],
        affects: [
            { text: 'الشات بالذكاء الاصطناعي: فهم نوع الطلب (صورة، صوت، ملف، بحث...) قبل ما الرد يبدأ.' },
            { route: 'admin.ai-intents.index', text: 'ده قاموس منفصل عن "أهداف الذكاء الاصطناعي" (اللي بتستخدمها قواعد التوجيه).' },
        ],
        warnings: [
            { level: 'warning', text: 'عبارة في السلة مش بتتطابق، والراوتر مش هيتعلّمها تاني ولا يشغّلها طول ما هي هناك. لو عايزها، رجّعها من السلة.' },
            { level: 'warning', text: 'الإيقاف بيعلّم العبارة "تحتاج مراجعة"، فمش هترجع تشتغل لوحدها حتى لو الموديل أكدها تاني.' },
            { level: 'info', text: 'الجدول ليه حد أقصى (ai.intent_router.max_learned_rows، افتراضي 5000) واللي في السلة بيتحسب منه.' },
        ],
    },

    en: {
        summary: 'Phrases the smart intent router learned: when a message is worded in a way the built-in dictionary does not know (e.g. "I wish I could see..." = make an image), the default model is asked once, and a confident answer is saved here so the next time it is understood instantly and for free.',
        audience: 'AI administrator. Needs the "Learned phrases" permissions: view, create, update, delete, change status, multiple delete.',
        purpose: [
            'Two kinds: "whole message" (exact) is active at once; "phrase inside message" becomes active after the model confirms it several times (the number on the "Waiting for confirmation" card).',
            'If the model returns a different intent for the same words, the phrase is switched off and marked "needs review"; the router never switches it back on by itself.',
            'Every change here (on, off, delete, restore) takes effect on the next message.',
        ],
        sections: [
            {
                title: 'Cards',
                items: [
                    { name: 'Total / Active / Waiting / Needs review', desc: 'Without the trash.' },
                    { name: 'Model calls saved', desc: 'How many times a learned phrase answered instead of the model.' },
                ],
            },
            {
                title: 'Try a phrase',
                items: [
                    { name: 'Test', desc: 'Shows what the chat would do with a message: dictionary, learned phrase, ask the model, or plain conversation. Nothing is sent, counted or learned. Needs "update".' },
                ],
            },
            {
                title: 'Toolbar and filters',
                items: [
                    { name: 'Search', desc: 'In the phrase; the typed text is normalized like the stored phrases (ى/ي, ة/ه, diacritics).' },
                    { name: 'All / On / Off / Trash', desc: 'Quick filters with counts (following the other filters too).' },
                    { name: 'Router state / intent / match type / source', desc: 'The screen\'s filters: waiting or needs review, the request kind, the match type, learned by the model or added by an admin.' },
                    { name: 'Delete selected / Add phrase', desc: 'Per your permissions.' },
                ],
            },
            {
                title: 'Table',
                items: [
                    { name: 'Phrase', desc: 'With match type, language and id; click to edit.' },
                    { name: 'Intent', desc: 'The request kind (image, voice, file...) and the file format if any.' },
                    { name: 'Router state', desc: 'Active / Waiting (confirmations n of total) / Needs review (times contradicted).' },
                    { name: 'Confidence / uses / source', desc: 'Highest confidence the model gave, uses and last use, who added it.' },
                    { name: 'Status', desc: 'On/off ("change status"). Off marks it "needs review"; on clears that.' },
                ],
            },
            {
                title: 'Phrase form',
                items: [
                    { name: 'Phrase', desc: '2–120 chars, saved normalized. Unique per intent and match type (the trash included). Set once, never changed.' },
                    { name: 'Match type', desc: 'Phrase inside message or whole message. Set once when adding.' },
                    { name: 'Intent', desc: 'One of the request kinds. Changing it to one the same phrase already has is refused.' },
                    { name: 'File format', desc: 'For "file output" only: PDF, Word, Excel or automatic.' },
                    { name: 'Status', desc: 'An admin phrase is active at once (100% confidence). Saved off, it is marked "needs review".' },
                ],
            },
        ],
        tasks: [
            {
                title: 'Teach the chat a new wording',
                steps: ['Click "Add phrase".', 'Type the phrase, pick the match type and the intent.', 'Save - it works from the next message.'],
            },
            {
                title: 'Review a contradicted phrase',
                steps: ['Pick "Needs review" in the router state filter.', 'If the intent is right switch it on; otherwise change the intent or move it to the trash.'],
            },
        ],
        affects: [
            { text: 'The AI chat: understanding the kind of request (image, voice, file, search...) before the reply starts.' },
            { route: 'admin.ai-intents.index', text: 'A separate dictionary from "AI intents" (the ones routing rules use).' },
        ],
        warnings: [
            { level: 'warning', text: 'A phrase in the trash never matches, and the router neither re-learns nor switches it on while it is there. Restore it to use it again.' },
            { level: 'warning', text: 'Switching off marks the phrase "needs review", so it does not come back on by itself even if the model confirms it again.' },
            { level: 'info', text: 'The table has a ceiling (ai.intent_router.max_learned_rows, 5000 by default) and trashed rows count toward it.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-learned-intents/index.vue (stats + tester + countries-style list)',
            'resources/js/modules/admin/themes/theme-1/views/ai-learned-intents/ModalCreateAndUpdate.vue (no translations)',
            'resources/js/composables/useAiRoutingScreens.js (useAiLearnedIntents: extra params state, intent, mode, source), stores/aiRoutingScreens.js',
            'Modules/AI/app/Http/Controllers/AiLearnedIntentController.php (CatalogController + stats, test; guarded by admin.screen)',
            'Modules/AI/app/Services/AiLearnedIntentAdminService.php (CatalogService: store normalizes, update intent clash, stats, test)',
            'Modules/AI/app/Repositories/AiLearnedIntentRepository.php (filters; changeStatus sets conflicts)',
            'Modules/AI/app/Models/AiLearnedIntent.php (SoftDeletes, status, state(), search normalization, cache flush on every change)',
            'Modules/AI/app/Services/AiLearnedIntentStore.php (active rows = status + not trashed; never re-creates or switches on a trashed row)',
        ],
        api: [
            'GET|POST       /api/admin/v1/ai-learned-intents                 (?status_counts=1, search, filterColumns, trashed, state, intent, mode, source)',
            'GET            /api/admin/v1/ai-learned-intents/stats',
            'POST           /api/admin/v1/ai-learned-intents/test            (= update)',
            'GET|PUT|DELETE /api/admin/v1/ai-learned-intents/{learnedIntent}',
            'PATCH          /api/admin/v1/ai-learned-intents/{learnedIntent}/status',
            'POST           /api/admin/v1/ai-learned-intents/delete-multiple',
            'POST           /api/admin/v1/ai-learned-intents/{learnedIntent}/restore',
            'DELETE         /api/admin/v1/ai-learned-intents/{learnedIntent}/force',
        ],
        tables: ['ai_learned_intents (phrase, match_mode, intent, file_format, confidence, confirmations, conflicts, hits, status, source, deleted_at)'],
        permissions: [
            'ai-learned-intents.view (screen, list, stats)',
            'ai-learned-intents.create · ai-learned-intents.update (edit, restore, the tester)',
            'ai-learned-intents.delete (trash, force delete) · ai-learned-intents.change-status · ai-learned-intents.multiple-delete',
        ],
        noTranslatableText: 'A learned phrase is the very words users type, which the router matches (plus an intent / mode / format code) - not a label to translate.',
        decisions: [
            {
                ar: 'الحالة في الـ API بقت `status` (true/false) زي كل الكتالوج، وحالة الراوتر (active/pending/conflict) اتنقلت لـ `state`.',
                en: 'The API `status` is now the catalog boolean like every catalog screen; the router state (active/pending/conflict) moved to `state`.',
            },
            {
                ar: 'الحذف بقى سلة محذوفات: العبارة اللي في السلة بتفضل "متشافة" عشان الراوتر ميتعلّمهاش تاني من الأول.',
                en: 'Delete is now the trash: a trashed phrase stays "seen" so the router does not learn it again from scratch.',
            },
        ],
    },
};
