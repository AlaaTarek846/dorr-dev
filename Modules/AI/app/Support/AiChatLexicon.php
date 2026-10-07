<?php

namespace Modules\AI\Support;

/**
 * Single source of truth for every "what does the user want?" word list
 * the chat layer uses to switch capabilities (image generation/edit,
 * voice reply, file output, web search, coding, ...).
 *
 * Why this exists: the same gates used to live as separate, hand-copied
 * arrays in AiRequiredCapabilityResolver and AiChatService, each covering
 * mostly Egyptian phrasing, each matched byte-exactly. Real users type
 * Egyptian, Gulf/Saudi, Modern Standard and English - often mixed in one
 * sentence - and with any spelling variant. A miss is invisible to the
 * user but feels like "the AI is dumb" ("I asked for a voice reply and it
 * answered in text"), so coverage and consistency matter here.
 *
 * All matching goes through ArabicTextNormalizer on BOTH sides, so lists
 * below are written in plain, natural spelling and never need duplicate
 * entries for ى/ي, ة/ه, hamza forms or diacritics.
 *
 * Two match styles:
 *  - phrase(): whole-phrase containment (for multi-word, unambiguous
 *    phrases).
 *  - verb + noun pair gates (mentionsAny + startsWithAny): for natural
 *    conjugations/pronoun suffixes ("اعملهالي", "ابغى اسمعه").
 */
final class AiChatLexicon
{
    // ---------------------------------------------------------------
    // Shared nouns
    // ---------------------------------------------------------------

    /** @var list<string> */
    public const IMAGE_NOUNS = [
        'صور', 'رسمه', 'لوحه', 'لوجو', 'شعار', 'ايقونه', 'بوستر', 'بانر', 'تصميم',
        'خلفيه', 'افاتار', 'كاريكاتير', 'انفوجرافيك', 'ستيكر', 'ملصق',
        'image', 'picture', 'photo', 'logo', 'poster', 'banner', 'icon', 'illustration',
        'artwork', 'wallpaper', 'avatar', 'sticker', 'drawing', 'infographic', 'thumbnail',
    ];

    /** @var list<string> */
    public const VIDEO_NOUNS = [
        'فيديو', 'فديو', 'مقطع فيديو', 'كليب', 'انيميشن', 'فيلم قصير',
        'video', 'animation', 'animated clip', 'short film',
    ];

    /** Talking ABOUT a video (usually an attached one), not asking for a new one. */
    public const VIDEO_ANALYSIS_PHRASES = [
        'ملخص', 'تلخيص', 'لخص', 'لخصلي', 'اشرح', 'اشرحلي', 'شرح', 'تفريغ', 'فرغ', 'ترجم', 'ترجمه', 'ترجمة', 'حلل', 'تحليل',
        'فيه ايه', 'في ايه', 'بيحصل ايه', 'ايه اللي', 'تعليق على',
        'summary', 'summarize', 'summarise', 'transcript', 'transcribe', 'explain', 'analyze', 'analyse', 'translate', 'subtitles', 'describe',
    ];

    /** @var list<string> */
    public const VIDEO_GENERATION_PHRASES = [
        'ولد فيديو', 'ولدلي فيديو', 'اعمل فيديو', 'اعملي فيديو', 'اعملى فيديو', 'اعملللي فيديو', 'عايز فيديو', 'عاوز فيديو',
        'سوي فيديو', 'سويلي فيديو', 'ابغى فيديو', 'ابي فيديو', 'صمم فيديو', 'انتج فيديو', 'طلعلي فيديو',
        'make a video', 'generate a video', 'create a video', 'make me a video', 'text to video',
    ];

    /** @var list<string> */
    public const CODE_NOUNS = [
        'كود', 'اكواد', 'كواد', 'سكريبت', 'دال', 'فنكشن', 'كلاس', 'api',
        'code', 'script', 'function', 'regex', 'snippet', 'sql', 'endpoint', '```',
    ];

    // ---------------------------------------------------------------
    // Verbs (prefix-matched at a word start so pronoun suffixes work:
    // "اعملهالي", "ارسملي", "make me", "generate me")
    // ---------------------------------------------------------------

    /**
     * Verbs meaning "create something new". Deliberately only STRONG
     * making-verbs: desire verbs ("عايز", "ابغى", "i want", "give me") are
     * far too generic next to a noun like "photo" ("give me a summary of
     * this photo") and are handled by explicit phrases instead.
     */
    public const CREATE_VERBS = [
        'اعمل', 'اعملي', 'اعملو', 'طلع', 'طلعلي', 'جهز', 'جهزلي', 'ابني', 'ابنيلي',
        'سوي', 'سويلي', 'اصنع', 'انشئ', 'ولد', 'صمم', 'ارسم', 'انتج',
        'make', 'generate', 'create', 'draw', 'design', 'produce', 'render', 'paint', 'sketch',
    ];

    /** Contexts where "code" means a discount/verification code, not source code. */
    public const CODE_NEGATIVE_CONTEXT = [
        'خصم', 'كوبون', 'بروموكود', 'برومو كود', 'رمز التحقق', 'كود التحقق', 'كود التفعيل', 'كود الدوله', 'كود بريدي', 'كود البريد',
        'discount code', 'promo code', 'coupon', 'verification code', 'country code', 'zip code', 'postal code', 'dress code',
        'area code', 'otp', 'voucher', 'gift code',
    ];

    /** Verbs meaning "change something that already exists". */
    public const CHANGE_VERBS = [
        // Egyptian + Saudi + MSA
        'غير', 'بدل', 'خلي', 'خلى', 'عدل', 'حول', 'زود', 'ضيف', 'اضف', 'شيل', 'امسح', 'احذف', 'ازل', 'نضف',
        'كبر', 'صغر', 'خليه', 'خليها', 'عدلها', 'عدله', 'حسن', 'اضبط', 'ظبط', 'صلح', 'استبدل', 'ابدل',
        // English
        'change', 'edit', 'recolor', 'colorize', 'add', 'remove', 'replace', 'swap', 'modify', 'adjust',
        'crop', 'resize', 'enhance', 'fix', 'retouch', 'turn it', 'make it', 'make him', 'make her', 'make them',
    ];

    /** Verbs meaning "write / produce text or code". */
    public const WRITE_VERBS = [
        'اكتب', 'اكتبلي', 'تكتب', 'تكتبلي', 'يكتب', 'اعمل', 'اعملي', 'ابعت', 'ابعتلي', 'ابني', 'ابنيلي',
        'صمم', 'برمج', 'برمجلي', 'جهز', 'جهزلي', 'سوي', 'سويلي', 'ابغى', 'ابي', 'عاوز', 'عايز', 'اريد', 'هات', 'جيب',
        'write', 'create', 'build', 'generate', 'implement', 'develop', 'make', 'give me', 'i need', 'i want',
    ];

    // ---------------------------------------------------------------
    // Direct phrases
    // ---------------------------------------------------------------

    /** Ask for the REPLY to come back as a voice note / spoken audio. */
    public const VOICE_REPLY_PHRASES = [
        // Egyptian
        'رد صوتي', 'رد بالصوت', 'برد صوتي', 'برد بالصوت', 'برساله صوتيه', 'قولي بصوت', 'رد صوت', 'ردلي بصوت', 'رد عليا بصوت', 'رد عليا صوتي', 'قولها بصوت', 'قولهالي بصوت',
        'قوللي بصوت', 'قولي بصوتك', 'قولها صوت', 'ابعتلي صوت', 'ابعتها صوت', 'ابعتهالي صوت', 'ابعتلي رساله صوتيه',
        'ابعتلي فويس', 'ابعتهالي فويس', 'ابعت فويس', 'فويس نوت', 'رساله صوتيه', 'رسايل صوتيه', 'سمعني الرد', 'سمعنيها',
        'اسمعني الرد', 'اسمعها', 'اقرأها بصوت', 'اقراها بصوت', 'اقرأه بصوت', 'اقراه بصوت', 'اقراها بصوت عالي',
        'كلمني بصوت', 'كلمني صوت', 'اتكلم معايا بصوت', 'رد صوتى', 'رد بفويس', 'رد فويس', 'بفويس', 'بالفويس',
        // Saudi / Gulf
        'رد صوتي', 'ابغى رد صوتي', 'ابي رد صوتي', 'ابغاك ترد بصوت', 'رد علي بصوت', 'رد علي صوت', 'ارسل لي صوت',
        'ارسل رد صوتي', 'ارسلها صوت', 'ارسلها لي صوت', 'ارسل لي رسالة صوتية', 'ابغى اسمعها',
        'ابي اسمعها', 'ابغاها صوت', 'ابيها صوت', 'تكلم معي بصوت', 'تكلم معاي بصوت', 'كلمني بالصوت', 'قولها لي بصوت',
        'قولها لي صوت', 'سمعني اياها', 'اسمعني اياها', 'ارسل فويس', 'ارسل لي فويس', 'سجل لي رد صوتي', 'سجل صوت',
        // MSA
        'بصوت مسموع', 'اجب صوتيا', 'اجب بالصوت', 'اجبني بصوتك', 'اجبني صوتيا', 'اقرأ لي بصوت', 'اقرا لي بصوت',
        'اقرأها لي', 'حول الرد الى صوت', 'حول الرد لصوت', 'حولها الى صوت', 'حولها لصوت', 'حولها الي صوت',
        // English
        'voice reply', 'voice message', 'voice note', 'voice answer', 'voice response', 'reply with voice', 'reply in voice',
        'reply by voice', 'respond with voice', 'respond by voice', 'respond in voice', 'answer with voice', 'answer by voice',
        'answer in voice', 'answer out loud', 'read it out loud', 'read this out loud', 'read that out loud', 'read it aloud',
        'read this aloud', 'read it to me', 'read that to me', 'read the answer', 'say it out loud', 'say it aloud',
        'speak the answer', 'tell me with your voice', 'tell me in voice',
        'send me a voice', 'send a voice', 'send voice', 'audio reply', 'audio response', 'audio answer', 'audio message',
        'text to speech', 'text-to-speech', 'tts', 'spoken reply', 'spoken answer', 'with your voice', 'in your voice',
        'out loud',
    ];

    /** Words that mean the user is NOT after a voice reply, even if they mention "voice". */
    public const VOICE_NEGATIONS = [
        'من غير صوت', 'بدون صوت', 'مش عايز صوت', 'مش عاوز صوت', 'مش عايزه صوت', 'لا تبعت صوت', 'لا ترسل صوت',
        'لا اريد صوت', 'ما ابي صوت', 'ما ابغى صوت', 'مو صوت', 'نص بس', 'كتابه بس', 'اكتب بس',
        'no voice', 'without voice', 'text only', 'dont send voice', "don't send voice", 'do not send voice',
        'no audio', 'not a voice', 'stop voice', 'stop the voice', 'turn off voice',
    ];

    /** Explicit "put it in a file" triggers (output intent). */
    public const FILE_OUTPUT_TRIGGERS = [
        // Egyptian / Saudi / MSA
        'في ملف', 'فى ملف', 'كملف', 'بملف', 'ملف pdf', 'ملف وورد', 'ملف اكسيل', 'ملف اكسل', 'ملف excel', 'ملف word',
        'اعمل ملف', 'اعملي ملف', 'اعملى ملف', 'اعملها ملف', 'اعملهالي ملف', 'سوي ملف', 'سويلي ملف', 'سويها ملف',
        'جهز ملف', 'جهزلي ملف', 'انشئ ملف', 'اصنع ملف', 'صمم ملف', 'اكتب ملف', 'اكتبه في ملف', 'اكتبها في ملف',
        'حطه في ملف', 'حطها في ملف', 'حطهالي في ملف', 'حطه فى ملف', 'حطها فى ملف', 'خليه ملف', 'خليها ملف',
        'ابعتلي ملف', 'ابعتهولي ملف', 'ابعتها ملف', 'ابعته ملف', 'ارسل لي ملف', 'ارسله ملف', 'ارسلها ملف', 'ارسله لي ملف',
        'حمله', 'حملها', 'حملهالي', 'نزله', 'نزلها', 'نزلهالي', 'نزل لي', 'حمل لي', 'تحميل', 'للتحميل', 'قابل للتحميل',
        'حول الي ملف', 'حوله الى ملف', 'حوله لملف', 'حولها لملف', 'حولها الى ملف', 'صدره', 'صدرها', 'تصدير', 'صدر لي',
        'ملخص في ملف', 'تقرير في ملف', 'استخرج ملف', 'طلعلي ملف', 'طلعه ملف', 'طلعها ملف', 'هاتلي ملف', 'ابغى ملف', 'ابي ملف',
        'لوورد', 'لاكسيل', 'لاكسل', 'لبي دي اف', 'لpdf', 'عايز ملف', 'عاوز ملف', 'عايزه ملف', 'اريد ملف', 'محتاج ملف', 'محتاجه ملف', 'احتاج ملف', 'اعمل لي ملف',
        // English
        'as a file', 'in a file', 'into a file', 'as a pdf', 'as pdf', 'as a word', 'as word', 'as excel', 'as a spreadsheet',
        'as a document', 'as a doc', 'in pdf', 'in word', 'in excel', 'to pdf', 'to word', 'to excel', 'into pdf',
        'into word', 'into excel', 'download', 'downloadable', 'export', 'save as', 'save it as', 'save it in a file',
        'put it in a file', 'put this in a file', 'send it as a file', 'send me a file', 'send me the file',
        'send me a pdf', 'send me a word', 'send me an excel', 'generate a file', 'generate a document',
        'generate a report', 'generate a pdf', 'generate a word', 'generate an excel', 'create a file',
        'create a document', 'create a report', 'create a pdf', 'create a word', 'create an excel', 'create a spreadsheet',
        'make a file', 'make a document', 'make a report', 'make a pdf', 'make a word', 'make an excel',
        'make a spreadsheet', 'write it to a file', 'turn it into a file', 'turn this into a file', 'convert it to',
        'convert this to', 'convert to pdf', 'convert to word', 'convert to excel', 'give me a file', 'give me a pdf',
        'give me a word', 'give me an excel', 'i need a file', 'i need a pdf', 'i want a file', 'i want a pdf',
        'file for it', 'file of it',
    ];

    /**
     * Words that only ever mean "an attached file I already have" (input),
     * never "make me a file" (output). Used to stop "لخص الملف ده" /
     * "summarize this file" from being read as a file-generation request.
     */
    public const FILE_INPUT_REFERENCES = [
        'الملف ده', 'الملف دا', 'الملف هذا', 'هذا الملف', 'الملف المرفق', 'المرفق', 'الملف اللي بعته', 'الملف اللى بعته',
        'الملف اللي رفعته', 'الملف اللى رفعته', 'الملف الي ارسلته', 'الملف اللي ارسلته', 'ملفي', 'ملفى', 'ملف بعته',
        'الملف اللي فوق', 'الملف اللى فوق', 'الملف الي فوق', 'ده الملف', 'دا الملف', 'دي الملف', 'ملف ده', 'ملف دا',
        'the file', 'this file', 'that file', 'attached file', 'the attachment', 'attached document', 'this document',
        'that document', 'my file', 'uploaded file', 'file i sent', 'file i uploaded', 'the pdf above', 'the document above',
        'this pdf', 'that pdf', 'this spreadsheet', 'this excel', 'this word',
    ];

    /** Verbs that read/analyse an input file (as opposed to generating one). */
    public const ANALYSIS_VERBS = [
        'لخص', 'لخصلي', 'اشرح', 'اشرحلي', 'حلل', 'حللي', 'اقرا', 'اقرأ', 'افتح', 'استخرج', 'ترجم', 'ترجملي', 'راجع',
        'راجعلي', 'قارن', 'ماذا', 'ايه', 'اي', 'وش', 'شو', 'فهمني', 'فسر', 'فسرلي', 'اعطني ملخص', 'عطني ملخص', 'عطيني ملخص',
        'summarize', 'summarise', 'analyze', 'analyse', 'explain', 'read', 'open', 'extract', 'translate', 'review',
        'compare', 'what does', 'what is in', "what's in", 'tell me about', 'describe', 'check',
    ];

    /** Format keywords, in detection priority order. */
    public const FILE_FORMAT_KEYWORDS = [
        'xlsx' => [
            'اكسيل', 'لاكسيل', 'لاكسل', 'اكسل', 'اكسيله', 'اكسل شيت', 'اكسيل شيت', 'excel', 'xlsx', 'xls', 'spreadsheet',
            'جدول بيانات', 'جدول اكسيل', 'جدول اكسل', 'جداول بيانات', 'csv', 'ورقة عمل', 'ورقه عمل', 'شيت اكسل', 'شيت اكسيل',
            'مصنف اكسل', 'مصنف اكسيل',
        ],
        'docx' => [
            'وورد', 'لوورد', 'ms word', 'microsoft word', 'word file', 'word document', 'word doc', 'a word file', 'in word', 'as word', 'to word', 'into word', 'docx', 'مستند وورد', 'ملف وورد', 'ملف ورد', 'مستند ورد',
            'ميكروسوفت وورد', 'مايكروسوفت وورد',
        ],
        'pdf' => [
            'pdf', 'لpdf', 'لبي دي اف', 'بي دي اف', 'بي دى اف', 'بى دى اف', 'بدف', 'بي-دي-اف', 'بيدياف', 'بي دي اف', 'بيدي اف', 'بي. دي. اف',
        ],
    ];

    /** Web search / live information. */
    public const WEB_SEARCH_PHRASES = [
        // Egyptian
        'اخر اخبار', 'اخبار اليوم', 'اخبار النهارده', 'اخبار النهاردة', 'اخبار دلوقتي', 'سعر الدولار اليوم', 'سعر الدولار النهارده',
        'سعر الدولار دلوقتي', 'سعر الذهب اليوم', 'سعر الذهب النهارده', 'سعر الذهب دلوقتي', 'سعر الفضه', 'سعر العمله', 'سعر اليورو',
        'سعر البنزين', 'سعر الدهب', 'اسعار الدهب', 'اسعار الدولار', 'اسعار العملات', 'اسعار الذهب', 'سعر الاسهم', 'سعر البتكوين',
        'نتيجه ماتش', 'نتيجة ماتش', 'نتايج الماتشات', 'نتيجه المباراه', 'ماتش النهارده', 'ماتش الاهلي', 'ماتش الزمالك',
        'ابحث على النت', 'ابحث في النت', 'ابحث على الانترنت', 'ابحث في الانترنت', 'دور على النت', 'دور في النت', 'دورلي على',
        'دورلي في النت', 'دور على الانترنت', 'دور في الانترنت', 'فتشلي', 'دورلي', 'شوفلي على النت', 'شوفلي في النت',
        'شوفلي في الانترنت', 'شوفلي على الانترنت', 'سيرش', 'ايه الجديد في', 'ايه الجديد ف', 'ايه اخر', 'ايه اخبار',
        'اخر اصدار', 'اخر نسخه', 'اخر تحديث', 'الطقس النهارده', 'الطقس اليوم', 'حاله الجو', 'درجه الحراره النهارده',
        'مواعيد الصلاه', 'مواقيت الصلاه', 'مين فاز', 'مين كسب', 'ايه نتيجه', 'الدوري المصري', 'ترتيب الدوري',
        // Saudi / Gulf
        'ابحث في الانترنت', 'ابحث لي', 'دور لي', 'دورلي', 'ابغى اخبار', 'وش اخبار', 'وش الاخبار', 'وش اخر', 'وش الجديد',
        'وش صار', 'وش صار في', 'اخر المستجدات', 'اخر التطورات', 'اخر الاخبار', 'سعر الريال', 'سعر الدولار الحين',
        'سعر الذهب الحين', 'اسعار الذهب اليوم', 'سعر البنزين اليوم', 'سعر اسهم', 'تداول اليوم', 'مؤشر تداول', 'مؤشر السوق',
        'سعر الاسهم اليوم', 'الطقس الحين', 'الطقس اليوم في', 'اوقات الصلاه', 'نتيجه المباراه', 'نتايج المباريات',
        'ترتيب الدوري السعودي', 'الدوري السعودي', 'جدول المباريات', 'مباراه اليوم', 'مباريات اليوم', 'مين فاز',
        'تحديثات', 'ايش صار', 'ايش اخر', 'ايش الجديد', 'شنو الجديد', 'شنو اخر', 'شو الجديد',
        // MSA
        'ابحث عبر الانترنت', 'ابحث في الشبكه', 'تصفح الانترنت', 'تحقق من الانترنت', 'اخر المستجدات', 'اخر الاخبار عن',
        'احدث الاخبار', 'احدث المعلومات', 'احدث اصدار', 'احدث نسخه', 'احدث اسعار', 'الاسعار الحاليه', 'السعر الحالي',
        'سعر الصرف', 'اسعار الصرف', 'سعر الصرف اليوم', 'حالة الطقس', 'حاله الطقس', 'ما هي اخر', 'ما هو اخر', 'ما احدث',
        'ما اخر', 'المعلومات الحديثه', 'معلومات محدثه', 'معلومات حديثه', 'بيانات حديثه',
        // English
        'latest news', 'breaking news', 'news today', 'news about', 'today news', "today's news", 'search the web', 'search online',
        'search the internet', 'search internet', 'search for', 'search google', 'google it', 'look it up', 'look up online',
        'look online', 'look on the web', 'browse the web', 'browse the internet', 'check online', 'check the web', 'check the internet',
        'find online', 'find on the web', 'current price', 'current price of', 'price today', 'price of bitcoin', 'bitcoin price',
        'gold price', 'oil price', 'stock price', 'exchange rate', "today's exchange rate", 'dollar rate', 'usd rate',
        'latest version', 'latest version of', 'latest release', 'latest update', 'newest version', 'new version of',
        "what's new in", 'whats new in', 'what is new in', "what's the latest", 'whats the latest', 'what is the latest',
        'as of today', 'as of now', 'live score', 'live scores', 'score of the match',
        'who won', 'who is winning', 'weather today', 'weather in', 'weather now', 'weather forecast', 'forecast for',
        'prayer times', 'most recent', 'recent news', 'recent updates',
    ];

    /** Deep research / academic. */
    public const RESEARCH_PHRASES = [
        'بحث علمي', 'بحث اكاديمي', 'ورقه بحثيه', 'ورقة بحثيه', 'دراسه علميه', 'دراسات علميه', 'مصادر موثوقه', 'مصادر علميه', 'مراجع علميه',
        'ابحث لي بحث', 'اعملي بحث', 'اعمل بحث', 'اعملى بحث', 'اكتب بحث', 'اكتبلي بحث', 'سوي بحث', 'سويلي بحث', 'ابغى بحث', 'ابي بحث',
        'محتاج بحث', 'عايز بحث', 'عاوز بحث', 'تقرير بحثي', 'مراجعه ادبيه', 'دراسه حاله', 'ابحاث', 'اوراق بحثيه', 'اطروحه', 'رساله ماجستير',
        'رساله دكتوراه', 'مقال علمي', 'مقاله علميه', 'توثيق', 'مع المصادر', 'مع المراجع', 'اذكر المصادر', 'اذكر المراجع', 'اعطني المصادر',
        'research', 'research paper', 'literature review', 'cite your sources', 'cite sources', 'with sources', 'with references', 'with citations',
        'academic sources', 'academic paper', 'scientific study', 'scientific studies', 'scientific paper', 'peer reviewed', 'peer-reviewed',
        'studies show', 'case study', 'deep research', 'in depth research', 'in-depth research', 'do research', 'do some research', 'thesis',
        'dissertation', 'white paper', 'whitepaper', 'systematic review', 'meta analysis', 'meta-analysis', 'bibliography', 'references for',
    ];

    /** Study / learning. */
    public const STUDY_PHRASES = [
        'اشرحلي', 'اشرح لي', 'اشرحلى', 'ذاكرلي', 'ذاكر لي', 'ذاكرلى', 'ساعدني افهم', 'ساعدني اذاكر', 'ساعدنى افهم', 'فهمني', 'فهمنى',
        'بسطلي', 'بسطها', 'بسط لي', 'عايز افهم', 'عاوز افهم', 'مش فاهم', 'ابغى افهم', 'ابي افهم', 'ما فهمت', 'مافهمت', 'وضحلي', 'وضح لي',
        'امتحان', 'واجب مدرسي', 'مراجعه', 'مراجعة', 'مذاكره', 'مذاكرة', 'اسئله وجوبه', 'اسئلة', 'كويز', 'اختبرني',
        'امتحني', 'سألني', 'اساليب المذاكره', 'ملخص المنهج', 'ملخص الدرس', 'شرح الدرس', 'حل المسأله', 'حل المسئله', 'حل المساله', 'حل التمرين',
        'حل المعادله', 'حل الواجب', 'حلل المسأله', 'مسأله رياضيه', 'مسئله رياضيه', 'خطوه بخطوه', 'خطوة بخطوة',
        'explain this to me', 'explain it to me', 'explain like', 'eli5', 'help me study', 'help me learn', 'help me understand', 'teach me',
        'homework', 'assignment', 'exam prep', 'exam', 'quiz me', 'quiz', 'test me', 'flashcards', 'study guide', 'lesson plan',
        'step by step', 'step-by-step', 'solve this problem', 'solve the equation', 'solve this equation', 'how does it work',
        'simple terms', 'in simple words', 'for a beginner', 'for beginners', 'break it down',
    ];

    /** Structured JSON-style output. */
    public const STRUCTURED_OUTPUT_PHRASES = [
        'json', 'بصيغه json', 'بصيغة json', 'شكل json', 'رجعهالي json', 'رجعه json', 'حوله json', 'حوله ل json', 'حول البيانات دي الي json',
        'حول البيانات الي json', 'حول البيانات الى json', 'ارجعها بصيغه', 'ارجع النتيجه بصيغه', 'ابغاها json', 'ابيها json', 'عايزها json',
        'عاوزها json', 'بشكل منظم', 'بصيغه منظمه', 'بصيغه جدول', 'في جدول', 'فى جدول', 'على شكل جدول', 'xml', 'yaml',
        'as json', 'in json', 'in json format', 'json format', 'return json', 'return it as json', 'respond in json', 'output json',
        'structured output', 'as a structured output', 'structured data', 'as yaml', 'as xml', 'schema',
    ];

    /** Phrases that unambiguously ask for a NEW image (no verb+noun gate needed). */
    public const IMAGE_GENERATION_PHRASES = [
        // Egyptian
        'ارسم', 'ارسملي', 'ارسملى', 'ارسم لي', 'ارسم لى', 'ولد صوره', 'ولدلي صوره', 'اعمل صوره', 'اعملي صوره', 'اعملى صوره', 'اعملها صوره',
        'اعملهالي صوره', 'طلعلي صوره', 'هاتلي صوره', 'هات صوره', 'جيبلي صوره', 'عايز صوره', 'عاوز صوره', 'عايزه صوره', 'عايز اشوف صوره',
        'صمم لوجو', 'صمملي لوجو', 'اعمل لوجو', 'اعملي لوجو', 'اعملى لوجو', 'عايز لوجو', 'عاوز لوجو', 'صمم شعار', 'صمملي شعار', 'اعمل شعار',
        'اعمل بوستر', 'صمم بوستر', 'اعمل بانر', 'صمم بانر', 'صمم اعلان', 'اعمل ستيكر', 'اعمل ايقونه', 'صمم ايقونه', 'اعمل رسمه', 'اعمل رسم',
        'ولد صوره', 'تخيل', 'تخيلي', 'تخيل لي', 'اعملي تصميم', 'اعمل تصميم', 'صمم لي', 'صمملي', 'عايز تصميم', 'عاوز تصميم', 'اعمل افاتار',
        'اعمل كاريكاتير', 'اعملي كاريكاتير', 'اعمل انفوجرافيك', 'اعملي انفوجرافيك', 'حول صورتي الى', 'حول الصوره الى', 'حولها الى صوره',
        // Saudi / Gulf
        'سوي صوره', 'سويلي صوره', 'سوي لي صوره', 'سوي لوجو', 'سويلي لوجو', 'سوي لي لوجو', 'سوي شعار', 'سويلي شعار', 'سوي بوستر',
        'سويلي بوستر', 'سوي تصميم', 'سويلي تصميم', 'سوي لي تصميم', 'ابغى صوره', 'ابي صوره', 'ابغى لوجو', 'ابي لوجو', 'ابغى تصميم',
        'ابي تصميم', 'ابغى شعار', 'ابي شعار', 'ابغى بوستر', 'ابي بوستر', 'ابغى رسمه', 'ابي رسمه', 'ابغى رسم', 'ابي رسم', 'جيب لي صوره',
        'جيبلي صوره', 'ارسم لي', 'ارسملي', 'ارسم لي صوره', 'خلني اشوف صوره', 'وريني صوره', 'ورني صوره', 'وريني رسمه', 'خلصلي تصميم',
        // MSA
        'انشئ صوره', 'انشئ لي صوره', 'اصنع صوره', 'اصنع لي صوره', 'ولد لي صوره', 'ولد لي لوجو', 'انشئ لوجو', 'اصنع لوجو', 'صمم لي لوجو',
        'صمم لي شعار', 'صمم لي بوستر', 'انشئ تصميم', 'اريد صوره', 'اريد لوجو', 'اريد تصميم', 'اريد شعار', 'ارغب بصوره', 'انتج صوره',
        // English
        'generate an image', 'generate a picture', 'generate a photo', 'generate image', 'generate me an image', 'generate a logo',
        'create an image', 'create a picture', 'create a photo', 'create image', 'create a logo', 'create a poster', 'create a banner',
        'create an icon', 'create an illustration', 'create artwork', 'make an image', 'make a picture', 'make a photo', 'make me an image',
        'make me a picture', 'make me a logo', 'make a logo', 'make a poster', 'make a banner', 'make an icon', 'draw me', 'draw a', 'draw an',
        'draw the', 'draw this', 'draw something', 'design a logo', 'design a poster', 'design a banner', 'design an icon', 'design a',
        'render an image', 'render a picture', 'paint a', 'paint me', 'sketch a', 'sketch me', 'illustrate', 'show me a picture of',
        'show me an image of', 'give me an image of', 'give me a picture of', 'i want an image', 'i want a picture', 'i want a logo',
        'i need an image', 'i need a picture', 'i need a logo', 'text to image', 'text-to-image', 'ai art', 'ai image', 'image of a',
        'picture of a', 'photo of a', 'imagine a', 'imagine an', 'imagine ',
    ];

    /** Phrases that unambiguously ask to EDIT the attached/previous image. */
    public const IMAGE_EDIT_PHRASES = [
        // Egyptian
        'عدل الصوره', 'عدل على الصوره', 'عدللي الصوره', 'غير الصوره', 'غير في الصوره', 'غير لون الصوره', 'غيرلي لون الصوره', 'لون الصوره',
        'غير الخلفيه', 'غير خلفيه', 'شيل الخلفيه', 'شيل الخلفية', 'امسح الخلفيه', 'احذف الخلفيه', 'ازل الخلفيه', 'بدل الخلفيه',
        'من غير خلفيه', 'بدون خلفيه', 'خلفيه شفافه', 'خلفيه بيضاء', 'خلفيه بيضا', 'شيل ده من الصوره', 'شيل الشخص', 'شيل الراجل', 'شيل البنت',
        'حط في الصوره', 'حط فى الصوره', 'ضيف في الصوره', 'ضيف فى الصوره', 'ضيف على الصوره', 'زود في الصوره', 'زود على الصوره',
        'خلي الصوره', 'خلى الصوره', 'خليها', 'حسن الصوره', 'حسنلي الصوره', 'صلح الصوره', 'نضف الصوره', 'وضح الصوره', 'قص الصوره',
        'كبر الصوره', 'صغر الصوره', 'اعمل الصوره', 'حول الصوره', 'حولها', 'لون الصوره', 'خلي الصوره', 'عدلها', 'عدل عليها',
        // Saudi / Gulf
        'عدل على الصوره', 'عدل لي الصوره', 'غير لي الصوره', 'غير لون الصوره', 'غير الخلفيه', 'شيل الخلفيه', 'شيل لي الخلفيه',
        'سوي الصوره', 'خل الصوره', 'خلها', 'خلي لون', 'غير اللون', 'غير لون', 'بدل اللون', 'بدل لون', 'حسن لي الصوره',
        'ابغى اعدل الصوره', 'ابي اعدل الصوره', 'ابغى تعدل الصوره', 'ابي تعدل الصوره', 'ابغى تغير', 'ابي تغير',
        // MSA
        'قم بتعديل الصوره', 'قم بتغيير الصوره', 'عدل هذه الصوره', 'غير هذه الصوره', 'اجري تعديلا', 'اجري تعديل على الصوره',
        'ازل الخلفيه من الصوره', 'اضف الى الصوره', 'اضف الي الصوره', 'اضف على الصوره', 'حرر الصوره', 'تعديل الصوره',
        // English
        'edit this image', 'edit the image', 'edit this picture', 'edit the picture', 'edit this photo', 'edit the photo', 'edit my photo',
        'edit my picture', 'edit my image', 'change the color', 'change color', 'change the colour', 'change colour', 'change the background',
        'change background', 'change this', 'change it to', 'recolor', 'recolour', 'colorize', 'colourise', 'remove the background',
        'remove background', 'remove bg', 'remove the bg', 'without background', 'no background', 'transparent background',
        'white background', 'blur the background', 'replace the background', 'swap the background', 'remove the person',
        'remove the object', 'remove this', 'add to the image', 'add to the picture', 'add to this image', 'add to this picture',
        'add a hat', 'add glasses', 'add a', 'make it red', 'make it blue', 'make it green', 'make it black', 'make it white',
        'make the background', 'make this image', 'make this picture', 'make the image', 'make the picture', 'turn this into',
        'turn the image', 'turn the picture', 'turn it into', 'turn him into', 'turn her into', 'enhance the image', 'enhance this',
        'enhance the photo', 'enhance my photo', 'retouch', 'touch up', 'upscale', 'sharpen', 'crop the image', 'crop this',
        'resize the image', 'resize this', 'fix the image', 'fix this image', 'fix this photo', 'fix the photo', 'restore this photo',
        'restore the photo', 'photoshop', 'cartoonize', 'make it a cartoon', 'make it cartoon', 'anime style', 'ghibli style',
        'in the style of', 'style transfer', 'same image but', 'same picture but', 'same photo but', 'modify the image',
        'modify this image', 'modify the picture', 'modify this picture', 'adjust the image', 'adjust this image',
    ];

    /** Phrases that mean "see/describe the attached image" (vision, not generation). */
    public const IMAGE_ANALYSIS_PHRASES = [
        'ايه لون', 'ايش لون', 'وش لون', 'ما لون', 'what color', 'what colour', 'which color', 'which colour',
        'ايه اللي في الصوره', 'ايه اللى فى الصوره', 'ايه في الصوره', 'ايه فى الصوره', 'اشرحلي الصوره', 'اشرح الصوره', 'وصف الصوره', 'وصفلي الصوره',
        'وصف الصوره', 'اقرا الصوره', 'اقرأ الصوره', 'اقرا اللي في الصوره', 'استخرج النص من الصوره', 'نص الصوره', 'مين ده', 'ده ايه', 'دي ايه',
        'ايه ده', 'ايه دي', 'وش في الصوره', 'وش هذي الصوره', 'وش هذا', 'وش هذي', 'ايش في الصوره', 'ايش هذي', 'ايش هذا', 'شنو في الصوره',
        'ما هذه الصوره', 'ما هذا', 'ماذا في الصوره', 'ماذا تري في الصوره', 'صف الصوره', 'صف لي الصوره', 'حلل الصوره', 'حللي الصوره',
        'what is in this image', "what's in this image", 'what is in the image', "what's in the image", 'what is in this picture',
        "what's in this picture", 'what is this', "what's this", 'what is that', 'describe this image', 'describe the image',
        'describe this picture', 'describe this photo', 'describe the photo', 'read this image', 'read the text', 'extract the text',
        'ocr', 'who is this', 'who is that', 'what do you see', 'analyze this image', 'analyse this image', 'analyze the image',
        'analyze this picture', 'analyze this photo', 'caption this', 'identify this', 'identify the',
    ];

    // ---------------------------------------------------------------
    // Matching helpers
    // ---------------------------------------------------------------

    /**
     * True when $text contains any of $phrases (both sides normalized).
     *
     * @param  list<string>  $phrases
     */
    public static function containsAny(string $text, array $phrases): bool
    {
        $haystack = ArabicTextNormalizer::normalize($text);

        return self::containsAnyNormalized($haystack, $phrases);
    }

    /**
     * Same as containsAny() but for text that is already normalized.
     *
     * @param  list<string>  $phrases
     */
    public static function containsAnyNormalized(string $haystack, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            $needle = ArabicTextNormalizer::normalize($phrase);

            if ($needle === '') {
                continue;
            }

            if (self::matchesPhrase($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Phrase match with a word-boundary on both sides for ASCII/latin
     * words (so "tts" never matches inside "attributes" and "draw" never
     * matches inside "withdrawal"), and a start-of-word boundary for
     * Arabic (so a verb still matches with an attached pronoun suffix:
     * "ارسملي" for "ارسم", but "صغير" never matches "غير").
     */
    private static function matchesPhrase(string $haystack, string $needle): bool
    {
        $quoted = preg_quote($needle, '/');

        // Latin phrase: strict boundaries on both sides.
        if (preg_match('/^[\x20-\x7E]+$/', $needle) === 1) {
            return (bool) preg_match('/(?<![\p{L}\p{M}\p{N}])'.$quoted.'(?![\p{L}\p{M}\p{N}])/u', $haystack);
        }

        // Arabic / mixed: start boundary only (suffixes/pronouns allowed).
        return (bool) preg_match('/(?<![\p{L}\p{M}\p{N}])'.$quoted.'/u', $haystack);
    }

    /**
     * Verb at the start of a word AND a noun anywhere: the robust
     * "verb + object" gate ("اعملهالي صوره", "can you make me a picture").
     *
     * @param  list<string>  $verbs
     * @param  list<string>  $nouns
     */
    public static function verbAndNoun(string $text, array $verbs, array $nouns): bool
    {
        $haystack = ArabicTextNormalizer::normalize($text);

        return self::nounMentioned($haystack, $nouns)
            && self::containsAnyNormalized($haystack, $verbs);
    }

    /**
     * Noun appearing anywhere (substring for Arabic roots like "صور" which
     * must hit "صوره/الصوره/صورتي/صور"; word-bounded for Latin).
     *
     * @param  list<string>  $nouns
     */
    public static function nounMentioned(string $haystack, array $nouns): bool
    {
        foreach ($nouns as $noun) {
            $needle = ArabicTextNormalizer::normalize($noun);

            if ($needle === '') {
                continue;
            }

            // Start-of-word boundary (optionally after a one/two-letter
            // Arabic proclitic: ال، لل، بال، وال، ب، ل، و) so "الصوره",
            // "بالصوره", "صورتي" hit the root "صور" while "تصوير" and
            // "رسمي" never do. Suffixes are allowed.
            $prefix = preg_match('/^[\x20-\x7E]+$/', $needle) === 1 ? '' : '(?:وال|بال|لل|ال|ب|ل|و)?';

            if (preg_match('/(?<![\p{L}\p{M}\p{N}])'.$prefix.preg_quote($needle, '/').'/u', $haystack)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Public phrase-containment check for text that is already normalized
     * (used by the learned-intent store so learned phrases follow exactly
     * the same word-boundary rules as the built-in dictionary).
     */
    public static function containsPhraseNormalized(string $haystack, string $phrase): bool
    {
        $needle = ArabicTextNormalizer::normalize($phrase);

        return $needle !== '' && self::matchesPhrase($haystack, $needle);
    }

    /** Whether the text names a file format at all (pdf / excel / word ...). */
    public static function mentionsFileFormat(string $text): bool
    {
        $n = ArabicTextNormalizer::normalize($text);

        foreach (self::FILE_FORMAT_KEYWORDS as $keywords) {
            if (self::containsAnyNormalized($n, $keywords)) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------
    // Decisions (what the chat layer actually calls)
    // ---------------------------------------------------------------

    public static function wantsVoiceReply(string $text): bool
    {
        $n = ArabicTextNormalizer::normalize($text);

        if (self::containsAnyNormalized($n, self::VOICE_NEGATIONS)) {
            return false;
        }

        return self::containsAnyNormalized($n, self::VOICE_REPLY_PHRASES);
    }

    /** True when the user asks to ANALYSE/READ something rather than produce it. */
    public static function startsWithAnalysisVerb(string $normalized): bool
    {
        foreach (self::ANALYSIS_VERBS as $verb) {
            $needle = ArabicTextNormalizer::normalize($verb);

            if ($needle !== '' && preg_match('/^(?:و|ف|ثم )?'.preg_quote($needle, '/').'(?![\p{L}\p{M}\p{N}])/u', $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Output intent: the user wants a FILE back. An explicit trigger always
     * counts; a bare format word ("pdf", "excel") only counts together with
     * a making-verb, and never when the sentence is just reading/analysing
     * an attached file ("لخص الملف ده", "summarize this pdf").
     */
    public static function wantsFileOutput(string $text): bool
    {
        $n = ArabicTextNormalizer::normalize($text);

        if (self::containsAnyNormalized($n, self::FILE_OUTPUT_TRIGGERS)) {
            return true;
        }

        if (self::startsWithAnalysisVerb($n)) {
            return false;
        }

        $mentionsFormat = false;

        foreach (self::FILE_FORMAT_KEYWORDS as $keywords) {
            if (self::containsAnyNormalized($n, $keywords)) {
                $mentionsFormat = true;
                break;
            }
        }

        return $mentionsFormat && self::containsAnyNormalized($n, self::CREATE_VERBS);
    }

    /**
     * Which file format the user wants BACK. When several formats are
     * mentioned ("حلل الاكسيل واعملي تقرير pdf") the one in an output
     * position ("في/الى/to/as/into <format>") wins over a mere input
     * mention, otherwise the last one mentioned, defaulting to pdf.
     */
    public static function detectRequestedFileFormat(string $text): string
    {
        $n = ArabicTextNormalizer::normalize($text);

        /** @var array<string, int> $positions */
        $positions = [];
        /** @var array<string, bool> $outputPosition */
        $outputPosition = [];

        foreach (self::FILE_FORMAT_KEYWORDS as $format => $keywords) {
            foreach ($keywords as $keyword) {
                $k = ArabicTextNormalizer::normalize($keyword);

                if ($k === '') {
                    continue;
                }

                $latin = preg_match('/^[\x20-\x7E]+$/', $k) === 1;
                $q = preg_quote($k, '/');
                $pattern = $latin
                    ? '/(?<![\p{L}\p{M}\p{N}])'.$q.'(?![\p{L}\p{M}\p{N}])/u'
                    : '/(?<![\p{L}\p{M}\p{N}])'.$q.'/u';

                if (preg_match_all($pattern, $n, $m, PREG_OFFSET_CAPTURE) > 0) {
                    $last = (int) end($m[0])[1];
                    $positions[$format] = max($positions[$format] ?? -1, $last);

                    $outPattern = '/(?:^|\s)(?:في|الي|الى|لي|ب|ل|to|into|as|in|ملف|file|a|an|save as|convert to|export to)\s*(?:ملف\s*)?'.$q.'/u';

                    if (preg_match($outPattern, $n)) {
                        $outputPosition[$format] = true;
                    }
                }
            }
        }

        if ($positions === []) {
            return 'pdf';
        }

        $inOutput = array_keys($outputPosition);

        if (count($inOutput) === 1) {
            return $inOutput[0];
        }

        $candidates = $inOutput !== [] ? array_intersect_key($positions, array_flip($inOutput)) : $positions;
        arsort($candidates);

        return (string) array_key_first($candidates);
    }

    /** New image requested (never an analysis of an attached one). */
    public static function wantsImageGeneration(string $text): bool
    {
        $n = ArabicTextNormalizer::normalize($text);

        if (self::containsAnyNormalized($n, self::IMAGE_ANALYSIS_PHRASES) || self::startsWithAnalysisVerb($n)) {
            return false;
        }

        return self::containsAnyNormalized($n, self::IMAGE_GENERATION_PHRASES)
            || (self::nounMentioned($n, self::IMAGE_NOUNS) && self::containsAnyNormalized($n, self::CREATE_VERBS));
    }

    /** New video requested (never a question about an attached video). */
    public static function wantsVideoGeneration(string $text): bool
    {
        $n = ArabicTextNormalizer::normalize($text);

        if (self::startsWithAnalysisVerb($n) || self::containsAnyNormalized($n, self::VIDEO_ANALYSIS_PHRASES)) {
            return false;
        }

        return self::containsAnyNormalized($n, self::VIDEO_GENERATION_PHRASES)
            || (self::nounMentioned($n, self::VIDEO_NOUNS) && self::containsAnyNormalized($n, self::CREATE_VERBS));
    }

    /**
     * Edit of an existing image. $recentImageExists lets a bare change verb
     * ("خلي الطفل لابس بدله") count right after an image was shown.
     */
    public static function wantsImageEdit(string $text, bool $recentImageExists = false): bool
    {
        $n = ArabicTextNormalizer::normalize($text);

        if (self::containsAnyNormalized($n, self::IMAGE_ANALYSIS_PHRASES)) {
            return false;
        }

        $mentions = $recentImageExists || self::nounMentioned($n, self::IMAGE_NOUNS);

        if (! $mentions) {
            return false;
        }

        return self::containsAnyNormalized($n, self::CHANGE_VERBS)
            || self::containsAnyNormalized($n, self::IMAGE_EDIT_PHRASES);
    }

    public static function wantsWebSearch(string $text): bool
    {
        return self::containsAny($text, self::WEB_SEARCH_PHRASES);
    }

    public static function wantsResearch(string $text): bool
    {
        return self::containsAny($text, self::RESEARCH_PHRASES);
    }

    public static function wantsStudyHelp(string $text): bool
    {
        return self::containsAny($text, self::STUDY_PHRASES);
    }

    public static function wantsStructuredOutput(string $text): bool
    {
        return self::containsAny($text, self::STRUCTURED_OUTPUT_PHRASES);
    }

    /** Ask for code: a writing verb plus a code noun. */
    public static function wantsCode(string $text): bool
    {
        // nounMentioned() also accepts Arabic proclitics, so "كود الخصم" is caught like "كود خصم".
        if (self::nounMentioned(ArabicTextNormalizer::normalize($text), self::CODE_NEGATIVE_CONTEXT)) {
            return false;
        }

        return self::verbAndNoun($text, self::WRITE_VERBS, self::CODE_NOUNS);
    }
}
