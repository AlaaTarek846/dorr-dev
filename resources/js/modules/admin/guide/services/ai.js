/**
 * Service map: DORR AI assistant (admin screens).
 *
 * Titles reuse the existing i18n keys (the same ones the sidebar shows), so a screen is never named twice.
 * A screen is "documented" once a guide file exists for its route in ../screens/ - nothing to flag here.
 */
export default {
    key: 'ai',
    module: 'AI',
    title: 'sidebar.ai',
    icon: 'ri-robot-2-line',

    intro: {
        ar: 'الشاشات دي بتتحكم في المساعد الذكي اللي بيستخدمه اليوزر ومقدم الخدمة والكابتن في تطبيقاتهم: مين بيرد، بكام، وبأنهي حدود، وإزاي بنراقب الجودة والأمان.',
        en: 'These screens control the AI assistant that users, providers and captains use in their apps: who answers, at what price, within which limits, and how quality and safety are monitored.',
    },

    // What an admin does the first time, in order. `route` makes the step clickable.
    firstRun: [
        {
            route: 'admin.ai-settings',
            ar: 'وصّل مزود واحد على الأقل: حط مفتاح API، واختبر الاتصال، وفعّله. من غير الخطوة دي المساعد مش هيرد على حد.',
            en: 'Connect at least one provider: add the API key, test the connection, enable it. Without this the assistant answers no one.',
        },
        {
            route: 'admin.ai-plans.index',
            ar: 'اتأكد إن فيه خطة تجربة مجانية مفعّلة، وبعدين ضيف الخطط المدفوعة وأسعار الدول.',
            en: 'Make sure an active free-trial plan exists, then add the paid plans and per-country prices.',
        },
        {
            route: 'admin.ai-trial-control.index',
            ar: 'تابع مين بيستخدم التجربة المجانية، واقفل على أي حد بيسيء الاستخدام.',
            en: 'Watch who uses the free trial, and block abuse.',
        },
        {
            route: 'admin.ai-subscriptions.index',
            ar: 'تابع الاشتراكات المدفوعة: تمديد أو إيقاف أو تجديد.',
            en: 'Follow paid subscriptions: extend, suspend, renewals.',
        },
        {
            route: null,
            ar: 'باقي المجموعات (التوجيه والأمان واللغة...) ضبط متقدم، والإعدادات الافتراضية بتشتغل من غيرها.',
            en: 'The other groups (routing, safety, language…) are advanced tuning; the defaults work without them.',
        },
    ],

    // The path one user message takes - each stage points at the group that controls it.
    flow: [
        { group: 'core', ar: 'اليوزر عنده خطة ودقايق؟', en: 'Does the user have a plan and minutes left?' },
        { group: 'routing', ar: 'أنهي مزود وموديل يرد؟', en: 'Which provider and model answers?' },
        { group: 'safety', ar: 'الرسالة والرد آمنين؟', en: 'Are the message and the answer safe?' },
        { group: 'knowledge', ar: 'محتاج ملفات أو معرفة؟', en: 'Does it need files or knowledge?' },
        { group: 'language', ar: 'يرد بأنهي لغة ولهجة؟', en: 'Which language and dialect?' },
        { group: 'requests', ar: 'كل ده بيتسجّل للمتابعة', en: 'Everything is logged for review' },
    ],

    groups: [
        {
            key: 'core',
            title: 'sidebar.ai_groups.core',
            icon: 'ri-settings-3-line',
            screens: [
                { route: 'admin.ai-settings', title: 'sidebar.ai_items.settings' },
                { route: 'admin.ai-plans.index', title: 'ai_plans.title' },
                { route: 'admin.ai-site-offers.index', title: 'ai_site_offers.title' },
                { route: 'admin.ai-site-requests.index', title: 'ai_site_requests.title' },
                { route: 'admin.ai-site-requests.show', title: 'ai_site_requests.show_title' },
                { route: 'admin.ai-site-edit-packs.index', title: 'ai_site_offers.packs_title' },
                { route: 'admin.ai-ar.index', title: 'ai_ar.title' },
                { route: 'admin.ai-world.index', title: 'ai_world.title' },
                { route: 'admin.ai-site-download.index', title: 'ai_site_download.title' },
                { route: 'admin.ai-sites.index', title: 'ai_sites.title' },
                { route: 'admin.ai-site-hosting-plans.index', title: 'ai_site_hosting.title' },
                { route: 'admin.ai-site-hostings.index', title: 'ai_site_hosting.hostings_title' },
                { route: 'admin.ai-subscriptions.index', title: 'ai_subscriptions.title' },
                { route: 'admin.ai-usage-sessions.index', title: 'ai_usage_sessions.title' },
                { route: 'admin.ai-trial-control.index', title: 'ai_trial_control.title' },
            ],
        },
        {
            key: 'routing',
            title: 'sidebar.ai_groups.routing',
            icon: 'ri-route-line',
            screens: [
                { route: 'admin.ai-intents.index', title: 'ai_intents.title' },
                { route: 'admin.ai-learned-intents.index', title: 'ai_learned_intents.title' },
                { route: 'admin.ai-routing-policies.index', title: 'ai_routing_policies.title' },
                { route: 'admin.ai-gateways.index', title: 'ai_gateways.title' },
                { route: 'admin.ai-routing-rules.index', title: 'ai_routing_rules.title' },
                { route: 'admin.ai-feature-flags.index', title: 'ai_feature_flags.title' },
                { route: 'admin.ai-domain-policies.index', title: 'ai_domain_policies.title' },
            ],
        },
        {
            key: 'safety',
            title: 'sidebar.ai_groups.safety',
            icon: 'ri-shield-check-line',
            screens: [
                { route: 'admin.ai-safety-policies.index', title: 'ai_safety_policies.title' },
                { route: 'admin.ai-safety-rules.index', title: 'ai_safety_rules.title' },
                { route: 'admin.ai-safety-events.index', title: 'ai_safety_events.title' },
                { route: 'admin.ai-safety-scans.index', title: 'ai_safety_scans.title' },
            ],
        },
        {
            key: 'security',
            title: 'sidebar.ai_groups.security',
            icon: 'ri-lock-2-line',
            screens: [
                { route: 'admin.ai-security-policies.index', title: 'ai_security_policies.title' },
                { route: 'admin.ai-data-policies.index', title: 'ai_data_policies.title' },
                { route: 'admin.ai-provider-data-rules.index', title: 'ai_provider_data_rules.title' },
                { route: 'admin.ai-security-events.index', title: 'ai_security_events.title' },
            ],
        },
        {
            key: 'requests',
            title: 'sidebar.ai_groups.requests',
            icon: 'ri-chat-history-line',
            screens: [
                { route: 'admin.ai-requests.index', title: 'ai_requests.title' },
                { route: 'admin.ai-responses.index', title: 'ai_responses.title' },
                { route: 'admin.ai-usage.index', title: 'ai_usage.title' },
                { route: 'admin.ai-verifications.index', title: 'ai_verifications.title' },
                { route: 'admin.ai-code-executions.index', title: 'ai_code_executions.title' },
                { route: 'admin.ai-audit-events.index', title: 'ai_audit_events.title' },
            ],
        },
        {
            key: 'conversations',
            title: 'sidebar.ai_groups.conversations',
            icon: 'ri-chat-3-line',
            screens: [
                { route: 'admin.ai-conversation-contexts.index', title: 'ai_conversation_contexts.title' },
                { route: 'admin.ai-conversation-attachments.index', title: 'ai_conversation_attachments.title' },
                { route: 'admin.ai-conversation-instructions.index', title: 'ai_conversation_instructions.title' },
            ],
        },
        {
            key: 'monitoring',
            title: 'sidebar.ai_groups.monitoring',
            icon: 'ri-pulse-line',
            screens: [
                { route: 'admin.ai-provider-logs.index', title: 'ai_provider_logs.title' },
                { route: 'admin.ai-provider-health.index', title: 'ai_provider_health.title' },
                { route: 'admin.ai-failovers.index', title: 'ai_failovers.title' },
                { route: 'admin.ai-reliability-metrics.index', title: 'ai_reliability_metrics.title' },
            ],
        },
        {
            key: 'language',
            title: 'sidebar.ai_groups.language',
            icon: 'ri-translate-2',
            screens: [
                { route: 'admin.ai-languages.index', title: 'ai_languages.title' },
                { route: 'admin.ai-language-variants.index', title: 'ai_language_variants.title' },
                { route: 'admin.ai-user-language-preferences.index', title: 'ai_user_language_preferences.title' },
                { route: 'admin.ai-language-evaluations.index', title: 'ai_language_evaluations.title' },
            ],
        },
        {
            key: 'knowledge',
            title: 'sidebar.ai_groups.knowledge',
            icon: 'ri-book-open-line',
            screens: [
                { route: 'admin.ai-files.index', title: 'ai_files.title' },
                { route: 'admin.ai-file-processing.index', title: 'ai_file_processing.title' },
                { route: 'admin.ai-knowledge-sources.index', title: 'ai_knowledge_sources.title' },
                { route: 'admin.ai-knowledge-chunks.index', title: 'ai_knowledge_chunks.title' },
                { route: 'admin.ai-document-generations.index', title: 'ai_document_generations.title' },
            ],
        },
        {
            key: 'projects',
            title: 'sidebar.ai_groups.projects',
            icon: 'ri-folder-3-line',
            screens: [
                { route: 'admin.ai-project-instructions.index', title: 'ai_project_instructions.title' },
                { route: 'admin.ai-project-context.index', title: 'ai_project_context.title' },
            ],
        },
        {
            key: 'benchmark',
            title: 'sidebar.ai_groups.benchmark',
            icon: 'ri-medal-line',
            screens: [
                { route: 'admin.ai-benchmark-cases.index', title: 'ai_benchmark_cases.title' },
                { route: 'admin.ai-benchmark-runs.index', title: 'ai_benchmark_runs.title' },
            ],
        },
    ],
};
