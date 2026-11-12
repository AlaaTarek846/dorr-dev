/**
 * Guide: AI benchmark runs (admin.ai-benchmark-runs.index).
 * Source of truth checked: views/ai-benchmark-runs/index.vue, Modules/AI/routes/admin.php (admin/v1/ai-benchmark-runs),
 * Http/Controllers/AiBenchmarkRunController.php (index, config, show, store), Services/AiBenchmarkRunService.php,
 * Services/AiBenchmarkRunner.php (run, runCase, buildMessages, scoreCorrectness, decidePassed),
 * Repositories/AiBenchmarkRunRepository.php, Models/AiBenchmarkRun.php, Models/AiBenchmarkCase.php,
 * Modules/AI/config/config.php (min_recommended_sample_size).
 */
export default {
    route: 'admin.ai-benchmark-runs.index',
    updated: '2026-11-11',

    ar: {
        summary: 'هنا بتشغّل اختبار جودة للمساعد الذكي على بنك الأسئلة (حالات الاختبار)، وبتشوف كل تشغيلة: كام سؤال نجح، ونسبة النجاح، والرد الفعلي لكل سؤال.',
        audience: 'مسؤول الجودة أو المسؤول الفني. فتح الشاشة محتاج "تشغيلات الـ Benchmark: عرض"، وزرار التشغيل محتاج "إضافة".',
        purpose: [
            'تقيس المساعد قبل وبعد ما تغيّر مزود أو موديل أو سياسة مجال.',
            'التشغيل بياخد كل الحالات المفعّلة (أو حالات مجال واحد)، ويبعت كل سؤال للمزود اللي التوجيه بيختاره، ويقيّم الرد.',
            'كل سؤال بيتبعت مع تعليمات النظام الأساسية + إضافة سياسة المجال لو فيه، من غير باقي مراحل الشات (مصادر المعرفة، التحقق…).',
            'التقييم: "إجابة" بتنجح لو المساعد ماامتنعش ولقى نص الكلمات المتوقعة على الأقل (أو مفيش كلمات متوقعة). "امتناع" بتنجح لو امتنع. "سؤال توضيحي" بتنجح لو الرد فيه علامة استفهام.',
        ],
        sections: [
            {
                title: 'شريط الأدوات',
                items: [
                    { name: 'إدارة الحالات', desc: 'يوديك لشاشة حالات الاختبار تضيف أو تعدّل الأسئلة.' },
                    { name: 'كل المسارات / مجال', desc: 'تختار تشغّل كل الحالات أو حالات مجال واحد (legal، health، education، code، marketing، general_info).' },
                    { name: 'شغّل Benchmark الآن', desc: 'بيشغّل التشغيلة ويستنى لحد ما تخلص (محتاج صلاحية "إضافة"). الزرار بيبقى "جاري التشغيل..." لحد النهاية.' },
                    { name: 'تنبيه العينة الصغيرة', desc: 'بيظهر لو أحدث تشغيلة في الصفحة فيها أقل من الحد المظبوط في السيرفر (30 افتراضيًا).' },
                ],
            },
            {
                title: 'الجدول (الأحدث فوق)',
                items: [
                    { name: 'التشغيلة', desc: 'رقمها، وتحته الموديل اللي التوجيه اختاره لأول سؤال.' },
                    { name: 'الحالة', desc: 'جارية أو مكتملة.' },
                    { name: 'الإجمالي والناجحة', desc: 'عدد الحالات اللي اتشغلت، والناجحة منها.' },
                    { name: 'نسبة النجاح', desc: 'أخضر من 80%، أصفر من 50%، أحمر أقل. "-" لو مفيش حالات.' },
                    { name: 'بدأت', desc: 'وقت بداية التشغيلة.' },
                    { name: '👁️ التفاصيل', desc: 'بيفتح نتايج كل سؤال.' },
                    { name: 'الترقيم', desc: 'تحت الجدول: الصفحات وعدد الصفوف.' },
                ],
            },
            {
                title: 'نافذة نتايج التشغيلة',
                items: [
                    { name: 'السؤال والمتوقع', desc: 'نص الحالة والسلوك المتوقع (إجابة، امتناع، سؤال توضيحي).' },
                    { name: 'امتنع', desc: 'نعم لو الرد فاضي أو فيه جملة الامتناع الرسمية.' },
                    { name: 'استشهاد مرجعي', desc: 'نعم لو الرد فيه رقم مرجع زي [1].' },
                    { name: 'دقة الإجابة', desc: 'نسبة الكلمات المتوقعة اللي ظهرت في الرد (من 0 لـ 1). "-" لو الحالة مالهاش كلمات متوقعة.' },
                    { name: 'ناجحة', desc: 'نتيجة التقييم.' },
                    { name: 'زمن الاستجابة', desc: 'بالمللي ثانية.' },
                    { name: 'الرد / سبب الفشل', desc: 'أول 140 حرف من الرد (حط الماوس تشوفه كله)، أو سبب الفشل بالأحمر لو الطلب نفسه فشل.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'أول تشغيلة',
                steps: [
                    'دوس "إدارة الحالات" وضيف أسئلة (30 على الأقل علشان النتيجة يعتمد عليها) وفعّلها.',
                    'ارجع هنا، واختار "كل المسارات" أو مجال معين.',
                    'دوس "شغّل Benchmark الآن" واستنى. ممكن ياخد وقت على حسب عدد الأسئلة.',
                    'دوس 👁️ على التشغيلة وراجع الأسئلة اللي فشلت.',
                ],
            },
            {
                title: 'مقارنة قبل وبعد تغيير موديل',
                steps: ['شغّل تشغيلة وسجّل نسبة النجاح.', 'غيّر الموديل أو المزود من الإعدادات.', 'شغّل تشغيلة تانية بنفس المجال وقارن.'],
            },
        ],
        affects: [
            { route: 'admin.ai-benchmark-cases.index', text: 'حالات الاختبار: مصدر الأسئلة اللي بتتشغل.' },
            { route: 'admin.ai-domain-policies.index', text: 'سياسات المجالات: إضافة التعليمات بتاعة المجال بتدخل مع كل سؤال من نفس المجال.' },
            { route: 'admin.ai-settings', text: 'إعدادات الذكاء الاصطناعي: المزودين اللي الأسئلة بتتبعت لهم.' },
        ],
        warnings: [
            { level: 'warning', text: 'كل سؤال طلب حقيقي للمزود وبيتحسب عليك فلوس. 100 حالة = 100 طلب.' },
            { level: 'warning', text: 'التشغيل بيحصل جوه نفس الطلب ومش في الخلفية، فالأسئلة الكتير بتخلي الصفحة تستنى كتير. لو التشغيلة وقعت في النص (خطأ أو الطلب قطع)، بتتعلّم "فشلت" بالنتايج اللي اتحسبت لحد ساعتها، ومش بتفضل "جارية".' },
            { level: 'info', text: 'النتيجة مش زي الشات الحقيقي بالظبط: مصادر المعرفة والتحقق ولغة اليوزر مش داخلين. وعلشان كده "استشهاد مرجعي" غالبًا "لا".' },
            { level: 'info', text: 'التقييم بالكلمات مش بالمعنى. اقرا الرد الفعلي قبل ما تحكم.' },
        ],
    },

    en: {
        summary: 'Run a quality test of the AI assistant on the case bank and review each run: cases passed, pass rate and the actual reply per case.',
        audience: 'Quality or technical owner. Needs "benchmark runs: view" to open and "create" for the run button.',
        purpose: [
            'Measure the assistant before and after changing a provider, model or domain policy.',
            'A run takes every active case (or one domain’s), sends each prompt to the provider routing picks, and scores the reply.',
            'Each prompt is sent with the base system prompt plus the domain policy addition when set — not the rest of the chat pipeline (knowledge, verification…).',
            'Scoring: "answer" passes when not abstained and at least half the expected keywords appear (or none are set); "abstain" passes when it abstained; "ask clarification" passes when the reply contains a question mark.',
        ],
        sections: [
            {
                title: 'Toolbar',
                items: [
                    { name: 'Manage cases', desc: 'Opens the benchmark cases screen to add or edit prompts.' },
                    { name: 'All domains / domain', desc: 'Run every case or one domain (legal, health, education, code, marketing, general_info).' },
                    { name: 'Run benchmark now', desc: 'Runs and waits until it ends (needs "create"); shows "Running..." meanwhile.' },
                    { name: 'Small-sample warning', desc: 'Shown when the newest run on the page has fewer cases than the server threshold (30 default).' },
                ],
            },
            {
                title: 'Table (newest first)',
                items: [
                    { name: 'Run', desc: 'Id, with the model routing picked for the first case.' },
                    { name: 'Status', desc: 'Running or completed.' },
                    { name: 'Total, passed', desc: 'Cases run and how many passed.' },
                    { name: 'Pass rate', desc: 'Green ≥ 80%, amber ≥ 50%, red below; "-" when no cases.' },
                    { name: 'Started', desc: 'Start time.' },
                    { name: '👁️ Details', desc: 'Opens the per-case results.' },
                    { name: 'Pagination', desc: 'Pages and rows per page.' },
                ],
            },
            {
                title: 'Run results window',
                items: [
                    { name: 'Prompt, expected', desc: 'Case text and expected behaviour (answer, abstain, ask clarification).' },
                    { name: 'Abstained', desc: 'Yes when the reply is empty or contains the official abstain sentence.' },
                    { name: 'Citation', desc: 'Yes when the reply contains a reference like [1].' },
                    { name: 'Correctness', desc: 'Share of expected keywords found (0–1); "-" when the case has none.' },
                    { name: 'Passed', desc: 'Scoring result.' },
                    { name: 'Latency', desc: 'In ms.' },
                    { name: 'Reply / failure reason', desc: 'First 140 characters (hover for all), or the failure reason in red when the call failed.' },
                ],
            },
        ],
        tasks: [
            {
                title: 'First run',
                steps: ['Click "Manage cases", add prompts (30+ for a reliable figure) and activate them.', 'Back here, pick "All domains" or one domain.', 'Click "Run benchmark now" and wait — time grows with the case count.', 'Click 👁️ on the run and review the failed cases.'],
            },
            {
                title: 'Compare before and after a model change',
                steps: ['Run once and note the pass rate.', 'Change the model or provider in AI settings.', 'Run again with the same domain and compare.'],
            },
        ],
        affects: [
            { route: 'admin.ai-benchmark-cases.index', text: 'Benchmark cases: the prompts that are run.' },
            { route: 'admin.ai-domain-policies.index', text: 'Domain policies: the domain’s prompt addition is sent with each case of that domain.' },
            { route: 'admin.ai-settings', text: 'AI settings: the providers the prompts are sent to.' },
        ],
        warnings: [
            { level: 'warning', text: 'Every case is a real, billed provider call: 100 cases = 100 calls.' },
            { level: 'warning', text: 'The run happens inside the request, not in the background, so many cases make the page wait long. If the run dies mid-way (an error or a time-out) it is marked "failed" with what was measured so far, instead of staying "running".' },
            { level: 'info', text: 'Not identical to the live chat: knowledge sources, verification and user language are not applied, so "Citation" is usually "no".' },
            { level: 'info', text: 'Scoring is keyword-based, not semantic. Read the actual reply before judging.' },
        ],
    },

    tech: {
        files: [
            'resources/js/modules/admin/themes/theme-1/views/ai-benchmark-runs/index.vue',
            'Modules/AI/app/Http/Controllers/AiBenchmarkRunController.php (index, config, show, store)',
            'Modules/AI/app/Services/AiBenchmarkRunService.php → AiBenchmarkRunner::run (synchronous)',
            'Modules/AI/app/Repositories/AiBenchmarkRunRepository.php (with results.case, newest first)',
            'Modules/AI/app/Models/AiBenchmarkRun.php, AiBenchmarkResult.php, AiBenchmarkCase.php',
        ],
        api: [
            'GET  /api/admin/v1/ai-benchmark-runs',
            'POST /api/admin/v1/ai-benchmark-runs { domain_key?, provider_id? }  (the screen sends domain_key only)',
            'GET  /api/admin/v1/ai-benchmark-runs/config → { min_recommended_sample_size }',
            'GET  /api/admin/v1/ai-benchmark-runs/{run}',
        ],
        tables: ['ai_benchmark_runs (status, total_cases, passed_cases, abstained_cases, pass_rate, model_key, started_at, finished_at)', 'ai_benchmark_results', 'ai_benchmark_cases (is_active, domain_key, expected_behavior, expected_answer_keywords)'],
        permissions: [
            'ai-benchmark-runs.view (screen, list, details, config)',
            'ai-benchmark-runs.create (Run benchmark now — store)',
        ],
        decisions: [
            {
                ar: 'AiBenchmarkRunner لسه بيشتغل جوه الـ HTTP request، بس try/catch/finally + register_shutdown_function بيعلّموا التشغيلة failed لو وقعت (خطأ أو fatal/time-out). NEEDS-DECISION: ننقله لـ queued job علشان الصفحة متستناش؟',
                en: 'AiBenchmarkRunner still runs inside the HTTP request, but try/catch/finally + register_shutdown_function mark the run failed if it dies (exception or fatal/time-out). NEEDS-DECISION: move it to a queued job so the page does not wait?',
            },
            {
                ar: 'عنوان الشاشة (ai_benchmark_runs.subtitle) بيقول "خط المحادثة الحقيقي"، بس buildMessages بيبعت system prompt + سياسة المجال بس، فمفيش استشهادات ولا تحقق. وقايمة المجالات مكتوبة ثابت في الـ view، وprovider_id مالوش اختيار في الشاشة. والتنبيه بيقرا أول صف في الصفحة الحالية مش أحدث تشغيلة لو انت في صفحة 2.',
                en: 'The subtitle (ai_benchmark_runs.subtitle) says "real chat pipeline" but buildMessages sends only the system prompt + domain policy, so no citations or verification. The domain list is hard-coded in the view, provider_id has no control on the screen, and the warning reads the first row of the current page, not the newest run, when on page 2.',
            },
        ],
    },
};
