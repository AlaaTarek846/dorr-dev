// Design preview — mirrors the screens/animations built in the real
// Kotlin/Compose project (androidApp/). Country data comes from the
// Laravel backend dropdown (same origin, dorr.test).

const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

const screens = {
  splash: $('#screen-splash'),
  login: $('#screen-login'),
  otp: $('#screen-otp'),
  main: $('#screen-main'),
};
const screenLabel = $('#current-screen-label');

function showScreen(name) {
  Object.values(screens).forEach((el) => el.classList.remove('active'));
  screens[name].classList.add('active');
  screenLabel.textContent = { splash: 'Splash', login: 'Login', otp: 'OTP', main: 'Home' }[name];
}

// ===================== Splash → Login =====================
setTimeout(() => showScreen('login'), 1600);

// ===================== Login =====================
const phoneInput = $('#phone-input');
const sendBtn = $('#btn-send-otp');
const dialBtn = $('#phone-dial-code');
const dialText = $('#phone-dial-text');
const flagEl = $('#phone-flag');
const menuEl = $('#phone-code-menu');
const countryListEl = $('#phone-code-list');
const countrySearch = $('#phone-country-search');
const countryEmpty = $('#phone-code-empty');
const termsCheck = $('#login-terms-check');
const phoneErrorEl = $('#phone-error');
const langBtns = $$('.login-lang-btn');
const langNameEls = $$('.login-lang-name');
const langMenuEls = $$('.login-lang-menu');

const LOGIN_COPY = {
  ar: {
    title: 'تسجيل الدخول',
    subtitle: 'أدخل رقم هاتفك سيتم إرسال كود للتأكيد',
    terms: 'بتسجيلك أنت موافق على شروط الاستخدام وسياسة الخصوصية',
    send: 'أرسل كود التأكيد',
    search: 'ابحث عن دولة',
    empty: 'لا توجد نتائج',
    powered: 'بدعم من',
    otpTitle: 'تأكيد رقم الهاتف',
    otpSubtitle: 'أدخل الكود المرسل إلى',
    otpConfirm: 'تأكيد',
    otpResend: 'إعادة إرسال الكود',
    otpTimer: 'إعادة الإرسال بعد {n} ثانية',
    errorInvalidLength: 'يجب أن يتكون رقم الهاتف من {length} أرقام.',
    errorInvalidStart: 'يجب أن يبدأ رقم الهاتف بالرقم {starts}.',
    errorGeneric: 'حدث خطأ ما. حاول مرة أخرى.',
  },
  en: {
    title: 'Sign in',
    subtitle: 'Enter your phone number and we\'ll send a confirmation code',
    terms: 'By signing in you agree to the Terms of Use and Privacy Policy',
    send: 'Send confirmation code',
    search: 'Search country',
    empty: 'No results',
    powered: 'Powered by',
    otpTitle: 'Verify your number',
    otpSubtitle: 'Enter the code sent to',
    otpConfirm: 'Confirm',
    otpResend: 'Resend code',
    otpTimer: 'Resend in {n}s',
    errorInvalidLength: 'The phone number must be {length} digits.',
    errorInvalidStart: 'The phone number must start with {starts}.',
    errorGeneric: 'Something went wrong. Please try again.',
  },
};

// ===================== Whole-app UI copy (mirrors the Compose i18n) =====================
// The login/OTP strings live in LOGIN_COPY above; this table covers the rest
// of the app (Home, Account, settings menu, sub-screens, sheets, dialogs,
// notifications, toasts). Picking a language re-paints every screen, exactly
// like LocalizedApp in the Android project.
const UI_COPY = {
  ar: {
    hello: 'مرحباً',
    guestUser: 'مستخدم زائر',
    bannerWelcome: 'مرحباً بك',
    bannerOffers: 'عروض خاصة',
    bannerBook: 'احجز خدمة الآن',
    quickContact: 'اتصل بنا',
    quickHistory: 'السجل',
    tabHome: 'الرئيسية',
    tabServices: 'الخدمات',
    tabHistory: 'السجل',
    tabAccount: 'الحساب',
    accountTitle: 'حسابي',
    settingsTitle: 'الإعدادات',
    premium: 'عضو مميز',
    walletLabel: 'رصيد المحفظة',
    walletAdd: 'إضافة رصيد',
    statOrders: 'إجمالي الطلبات',
    statRating: 'التقييم',
    statActive: 'الطلبات النشطة',
    accPersonalTitle: 'البيانات الشخصية',
    accPersonalSub: 'الاسم والهاتف والبريد',
    accSettingsTitle: 'الإعدادات',
    accSettingsSub: 'اللغة والإشعارات والحساب',
    accPlacesTitle: 'العناوين',
    accPlacesSub: 'عناوين المنزل والعمل',
    accPaymentsTitle: 'طرق الدفع',
    accPaymentsSub: 'البطاقات والمحافظ',
    accPromoTitle: 'أكواد الخصم',
    accPromoSub: 'خصومات وعروض خاصة',
    accHelpTitle: 'المساعدة والدعم',
    accHelpSub: 'الأسئلة الشائعة وتواصل معنا',
    setPersonalTitle: 'البيانات الشخصية',
    setPersonalSub: 'الاسم والهاتف والبريد',
    setNotifTitle: 'الإشعارات',
    setNotifSub: 'التحكم في التنبيهات',
    setLangTitle: 'اللغة',
    setLangSub: 'لغة واجهة التطبيق',
    setDarkTitle: 'الوضع الليلي',
    setDarkSub: 'مظهر داكن للتطبيق',
    setFaqTitle: 'الأسئلة الشائعة',
    setFaqSub: 'إجابات سريعة',
    setContactTitle: 'تواصل معنا',
    setContactSub: 'هاتف واتساب وبريد',
    setAboutTitle: 'عن التطبيق',
    setAboutSub: 'الإصدار والمعلومات',
    setPrivacyTitle: 'سياسة الخصوصية',
    setPrivacySub: 'كيف نتعامل مع بياناتك',
    setLogoutTitle: 'تسجيل خروج',
    setLogoutSub: 'الخروج من هذا الجهاز',
    setDeleteTitle: 'حذف الحساب',
    setDeleteSub: 'إجراء لا يمكن التراجع عنه',
    pdTitle: 'البيانات الشخصية',
    pdName: 'الاسم',
    pdGender: 'الجنس',
    pdPhone: 'رقم الهاتف',
    pdEmail: 'البريد الإلكتروني',
    pdNotAdded: 'غير مضاف',
    genderUnspecified: 'غير محدد',
    genderMale: 'ذكر',
    genderFemale: 'أنثى',
    editNameTitle: 'تعديل الاسم',
    editNameLabel: 'الاسم',
    editNameHint: 'اكتب الاسم كما يظهر في حسابك.',
    save: 'حفظ',
    toastEnterName: 'اكتب الاسم',
    toastNameUpdated: 'تم تحديث الاسم',
    editGenderTitle: 'تعديل الجنس',
    editGenderHint: 'اختر الجنس ثم احفظ.',
    toastPickGender: 'اختر الجنس',
    toastGenderUpdated: 'تم تحديث الجنس',
    editPhoneTitle: 'تعديل رقم الهاتف',
    editPhoneLabel: 'رقم الهاتف',
    phoneCodeLabel: 'كود الدولة',
    countrySearchPlaceholder: 'ابحث عن دولة',
    countrySearchEmpty: 'لا توجد نتائج',
    editPhoneHint: 'اكتب الرقم الجديد. بنرسل كود تأكيد، والتعديل يتم بعد التأكد.',
    sendCode: 'إرسال الكود',
    toastValidPhone: 'اكتب رقم هاتف صحيح',
    editEmailTitle: 'تعديل البريد الإلكتروني',
    editEmailLabel: 'البريد الإلكتروني',
    editEmailHint: 'اكتب البريد الجديد. بنرسل كود تأكيد، والتعديل يتم بعد التأكد.',
    toastValidEmail: 'اكتب بريد إلكتروني صحيح',
    otpHint: 'أدخل كود التأكيد المرسل إلى {target}، وبعد التأكيد يتم حفظ التعديل.',
    otpTimer: 'إعادة الإرسال بعد {n} ثانية',
    otpResend: 'إعادة إرسال الكود',
    otpDevHint: 'نسخة تجريبية: الكود 123456',
    confirmBtn: 'تأكيد',
    toastWrongCode: 'الكود غير صحيح',
    toastCodeSent: 'تم إرسال الكود',
    toastPhoneUpdated: 'تم تحديث رقم الهاتف',
    toastEmailUpdated: 'تم تحديث البريد الإلكتروني',
    notifTitle: 'الإشعارات',
    notifMarkAll: 'قراءة الكل',
    notifEmptyTitle: 'لا توجد إشعارات',
    notifEmptySub: 'ستظهر هنا الإشعارات عند وجود تحديثات جديدة',
    notifToggle1Title: 'الإشعارات الفورية',
    notifToggle1Desc: 'استقبل إشعارات على هذا الجهاز',
    notifToggle2Title: 'تحديثات الطلبات والخدمة',
    notifToggle2Desc: 'تغييرات حالة طلباتك',
    notifToggle3Title: 'العروض والتخفيضات',
    notifToggle3Desc: 'أخبار وعروض خاصة',
    notifToggle4Title: 'إشعارات البريد الإلكتروني',
    notifToggle4Desc: 'استقبل التحديثات على بريدك أيضاً',
    privacyTitle: 'سياسة الخصوصية',
    privacyHead: 'خصوصيتك مهمة',
    privacyBody: 'هذا نص مبدئي لسياسة الخصوصية. استبدله بسياستك الحقيقية قبل الإطلاق: ما هي البيانات التي تجمعها، لماذا تجمعها، كيف يتم تخزينها، مع من تتم مشاركتها، وكيف يمكن للمستخدم طلب حذف بياناته.\n\nحتى ذلك الحين، هذه الشاشة موجودة فقط لتوضيح شكل وتنسيق صفحة محتوى ثابت طويلة.',
    faqTitle: 'الأسئلة الشائعة',
    faq1q: 'إزاي أقدر أعدل بياناتي الشخصية؟',
    faq1a: 'افتح الحساب ← البيانات الشخصية، ثم اختر الحقل اللي عاوز تعدّله.',
    faq2q: 'إزاي أغيّر لغة التطبيق؟',
    faq2a: 'افتح الحساب ← اللغة واختر العربية أو الإنجليزية.',
    faq3q: 'التطبيق بيدعم الوضع الليلي؟',
    faq3a: 'أيوة — فعّله من الحساب ← الوضع الليلي.',
    faq4q: 'إزاي أتواصل مع الدعم؟',
    faq4a: 'افتح الحساب ← تواصل معنا للاتصال أو واتساب أو إيميل.',
    contactTitle: 'تواصل معنا',
    contactCall: 'اتصال هاتفي',
    contactWhatsapp: 'واتساب',
    contactEmail: 'البريد الإلكتروني',
    aboutHead: 'عن التطبيق',
    aboutVersion: 'الإصدار 1.0.0',
    aboutBody: 'تطبيق عام لإدارة الخدمات. هذه الشاشة، زي باقي التطبيق، نقطة بداية تقدر تخصصها حسب نشاطك.',
    closeLabel: 'إغلاق',
    goBack: 'رجوع',
    verified: 'موثّق',
    placeholderSub: 'سيتم إضافة شاشات هذا القسم لاحقاً.',
    langDialogTitle: 'اللغة',
    langLoading: 'جاري تحميل اللغات…',
    langLoadFail: 'تعذّر تحميل اللغات',
    logoutConfirm: 'هل أنت متأكد من تسجيل الخروج؟',
    deleteConfirm: 'سيتم حذف حسابك وكل بياناتك نهائياً. هل أنت متأكد؟',
    comingSoon: 'قريباً',
    walletDemo: 'إضافة الرصيد لسه مش متوصلة في النسخة التجريبية',
    notifNow: 'الآن',
    notifMin: 'منذ {n} دقيقة',
    notifHour: 'منذ {n} ساعة',
    notifDay: 'منذ يوم',
    notifDays: 'منذ {n} يوم',
    notifWeeks: 'منذ {n} أسابيع',
    notifDetailDefault: 'إشعار',
    notif1Title: 'تحديث حالة طلبك',
    notif1Body: 'طلب الخدمة بتاعك بقى "قيد التنفيذ".',
    notif2Title: 'عرض سعر جاهز للمراجعة',
    notif2Body: 'راجع البنود واعتمد العرض للمتابعة.',
    notif3Title: 'فاتورة جديدة',
    notif3Body: 'تم إصدار فاتورة لآخر خدمة طلبتها.',
    notif4Title: 'صيانة قادمة',
    notif4Body: 'في فحص دوري مستحق قريبًا.',
    notif5Title: 'عرض لفترة محدودة',
    notif5Body: 'عندنا عرض خاص الأسبوع ده.',
    notif6Title: 'أهلاً بيك في التطبيق',
    notif6Body: 'شكرًا لتسجيلك معانا.',
    deviceLabel: 'معاينة تصميم — Dorr Android (Compose)',
  },
  en: {
    hello: 'Hello',
    guestUser: 'Guest user',
    bannerWelcome: 'Welcome',
    bannerOffers: 'Special offers',
    bannerBook: 'Book a service now',
    quickContact: 'Contact us',
    quickHistory: 'History',
    tabHome: 'Home',
    tabServices: 'Services',
    tabHistory: 'History',
    tabAccount: 'Account',
    accountTitle: 'My account',
    settingsTitle: 'Settings',
    premium: 'Premium member',
    walletLabel: 'Wallet balance',
    walletAdd: 'Add funds',
    statOrders: 'Total orders',
    statRating: 'Rating',
    statActive: 'Active orders',
    accPersonalTitle: 'Personal data',
    accPersonalSub: 'Name, phone and email',
    accSettingsTitle: 'Settings',
    accSettingsSub: 'Language, notifications and account',
    accPlacesTitle: 'Addresses',
    accPlacesSub: 'Home and work addresses',
    accPaymentsTitle: 'Payment methods',
    accPaymentsSub: 'Cards and wallets',
    accPromoTitle: 'Discount codes',
    accPromoSub: 'Discounts and special offers',
    accHelpTitle: 'Help & support',
    accHelpSub: 'FAQs and contact us',
    setPersonalTitle: 'Personal data',
    setPersonalSub: 'Name, phone and email',
    setNotifTitle: 'Notifications',
    setNotifSub: 'Manage alerts',
    setLangTitle: 'Language',
    setLangSub: 'App UI language',
    setDarkTitle: 'Dark mode',
    setDarkSub: 'Dark theme for the app',
    setFaqTitle: 'FAQs',
    setFaqSub: 'Quick answers',
    setContactTitle: 'Contact us',
    setContactSub: 'Phone, WhatsApp and email',
    setAboutTitle: 'About',
    setAboutSub: 'Version and information',
    setPrivacyTitle: 'Privacy policy',
    setPrivacySub: 'How we handle your data',
    setLogoutTitle: 'Sign out',
    setLogoutSub: 'Leave this device',
    setDeleteTitle: 'Delete account',
    setDeleteSub: 'Cannot be undone',
    pdTitle: 'Personal data',
    pdName: 'Name',
    pdGender: 'Gender',
    pdPhone: 'Phone number',
    pdEmail: 'Email',
    pdNotAdded: 'Not added',
    genderUnspecified: 'Unspecified',
    genderMale: 'Male',
    genderFemale: 'Female',
    editNameTitle: 'Edit name',
    editNameLabel: 'Name',
    editNameHint: 'Type the name as it appears on your account.',
    save: 'Save',
    toastEnterName: 'Enter a name',
    toastNameUpdated: 'Name updated',
    editGenderTitle: 'Edit gender',
    editGenderHint: 'Choose a gender then save.',
    toastPickGender: 'Choose a gender',
    toastGenderUpdated: 'Gender updated',
    editPhoneTitle: 'Edit phone number',
    editPhoneLabel: 'Phone number',
    phoneCodeLabel: 'Country code',
    countrySearchPlaceholder: 'Search for a country',
    countrySearchEmpty: 'No results',
    editPhoneHint: 'Enter the new number. We\'ll send a code and the change applies after verification.',
    sendCode: 'Send code',
    toastValidPhone: 'Enter a valid phone number',
    editEmailTitle: 'Edit email',
    editEmailLabel: 'Email',
    editEmailHint: 'Enter the new email. We\'ll send a code and the change applies after verification.',
    toastValidEmail: 'Enter a valid email',
    otpHint: 'Enter the code sent to {target}. The change is saved after verification.',
    otpTimer: 'Resend in {n}s',
    otpResend: 'Resend code',
    otpDevHint: 'Demo: code is 123456',
    confirmBtn: 'Confirm',
    toastWrongCode: 'Incorrect code',
    toastCodeSent: 'Code sent',
    toastPhoneUpdated: 'Phone number updated',
    toastEmailUpdated: 'Email updated',
    notifTitle: 'Notifications',
    notifMarkAll: 'Mark all read',
    notifEmptyTitle: 'No notifications',
    notifEmptySub: 'Notifications will appear here when there are updates',
    notifToggle1Title: 'Push notifications',
    notifToggle1Desc: 'Receive notifications on this device',
    notifToggle2Title: 'Order & service updates',
    notifToggle2Desc: 'Changes to your order status',
    notifToggle3Title: 'Offers and discounts',
    notifToggle3Desc: 'News and special offers',
    notifToggle4Title: 'Email notifications',
    notifToggle4Desc: 'Also receive updates on your email',
    privacyTitle: 'Privacy policy',
    privacyHead: 'Your privacy matters',
    privacyBody: 'This is a placeholder privacy policy. Replace it with your real policy before launch: what data you collect, why you collect it, how it is stored, who it is shared with, and how users can request their data to be deleted.\n\nUntil then, this screen only exists to show the shape and layout of a long static content page.',
    faqTitle: 'FAQs',
    faq1q: 'How do I edit my personal data?',
    faq1a: 'Open Account ← Personal data, then pick the field you want to change.',
    faq2q: 'How do I change the app language?',
    faq2a: 'Open Account ← Language and choose Arabic or English.',
    faq3q: 'Does the app support dark mode?',
    faq3a: 'Yes — enable it from Account ← Dark mode.',
    faq4q: 'How do I contact support?',
    faq4a: 'Open Account ← Contact us to call, WhatsApp or email us.',
    contactTitle: 'Contact us',
    contactCall: 'Phone call',
    contactWhatsapp: 'WhatsApp',
    contactEmail: 'Email',
    aboutHead: 'About',
    aboutVersion: 'Version 1.0.0',
    aboutBody: 'A general service-management app. This screen, like the rest of the app, is a starting point you can tailor to your business.',
    closeLabel: 'Close',
    goBack: 'Back',
    verified: 'Verified',
    placeholderSub: 'This section\'s screens will be added later.',
    langDialogTitle: 'Language',
    langLoading: 'Loading languages…',
    langLoadFail: 'Failed to load languages',
    logoutConfirm: 'Are you sure you want to sign out?',
    deleteConfirm: 'Your account and all data will be permanently deleted. Are you sure?',
    comingSoon: 'Coming soon',
    walletDemo: 'Adding funds isn\'t wired up in this demo yet',
    notifNow: 'now',
    notifMin: '{n} min ago',
    notifHour: '{n} hr ago',
    notifDay: '1 day ago',
    notifDays: '{n} days ago',
    notifWeeks: '{n} weeks ago',
    notifDetailDefault: 'Notification',
    notif1Title: 'Order status update',
    notif1Body: 'Your service order is now "in progress".',
    notif2Title: 'Quote ready for review',
    notif2Body: 'Review the items and approve the quote to continue.',
    notif3Title: 'New invoice',
    notif3Body: 'An invoice was issued for your latest service order.',
    notif4Title: 'Upcoming maintenance',
    notif4Body: 'A scheduled check-up is due soon.',
    notif5Title: 'Limited-time offer',
    notif5Body: 'We have a special offer this week.',
    notif6Title: 'Welcome to the app',
    notif6Body: 'Thank you for signing up.',
    deviceLabel: 'Design preview — Dorr Android (Compose)',
  },
};

const UI_COPY_KEYS = Object.keys(UI_COPY.ar);

function t(key, params) {
  const dict = UI_COPY[selectedLanguageCode] || UI_COPY.ar;
  let str = dict[key] ?? UI_COPY.ar[key] ?? key;
  if (params) {
    Object.entries(params).forEach(([k, v]) => {
      str = str.replace(new RegExp(`{${k}}`, 'g'), v);
    });
  }
  return str;
}

function persistLanguage(code) {
  try {
    localStorage.setItem('dorr_preview_lang', code);
  } catch (_) { /* private mode etc. */ }
}

function storedLanguage() {
  try {
    return localStorage.getItem('dorr_preview_lang');
  } catch (_) {
    return null;
  }
}

let languages = [];
let selectedLanguageCode = 'ar';

function syncSendEnabled() {
  sendBtn.disabled =
    phoneInput.value.length !== phoneLength ||
    !phoneInput.value.startsWith(phoneStartsWith) ||
    !termsCheck.checked;
}

// Seeded default country is Saudi Arabia until dropdown answer.
let dialCode = '+966';
let phoneLength = 9;
let phoneStartsWith = '';
let flagCode = 'sa';
let selectedCountryId = null;
let countries = [];
let countryQuery = '';
let appName = '';
let brandImageShown = false;

const apiHeaders = { Accept: 'application/json', 'X-Locale': 'ar' };

function formatDialCode(value) {
  const digits = String(value ?? '').trim().replace(/^\+/, '');
  return digits ? `+${digits}` : '';
}

function flagUrl(code) {
  return `https://flagcdn.com/w40/${String(code || '').toLowerCase()}.png`;
}

function languageFlagCode(language) {
  const fromFlag = language?.flag?.code;
  if (fromFlag) return String(fromFlag).toLowerCase();
  const code = String(language?.code || '').toLowerCase();
  const fallbacks = { ar: 'sa', en: 'gb' };
  return fallbacks[code] || code.slice(0, 2);
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[ch]));
}

function applyCountry(country) {
  selectedCountryId = country.id;
  flagCode = country.flag?.code || country.code || flagCode;
  dialCode = formatDialCode(country.dial_code) || dialCode;
  phoneLength = country.phone_length || phoneLength;
  phoneStartsWith = country.phone_starts_with || '';
  dialText.textContent = dialCode;
  flagEl.src = flagUrl(flagCode);
  flagEl.alt = flagCode;
  phoneInput.maxLength = phoneLength;
  phoneInput.placeholder = phoneStartsWith + '*'.repeat(Math.max(0, phoneLength - phoneStartsWith.length));
  phoneInput.value = phoneInput.value.replace(/\D/g, '').slice(0, phoneLength);
  syncSendEnabled();
  renderCountryMenu();
}

function syncPhoneError() {
  const copy = LOGIN_COPY[selectedLanguageCode] || LOGIN_COPY.ar;
  const value = phoneInput.value;
  let message = null;
  if (value) {
    if (value.length !== phoneLength) {
      message = copy.errorInvalidLength.replace('{length}', String(phoneLength));
    } else if (phoneStartsWith && !value.startsWith(phoneStartsWith)) {
      message = copy.errorInvalidStart.replace('{starts}', phoneStartsWith);
    }
  }
  if (message) {
    phoneInput.closest('.screen-view').classList.add('has-error');
    phoneErrorEl.textContent = message;
    phoneErrorEl.hidden = false;
  } else {
    phoneInput.closest('.screen-view').classList.remove('has-error');
    phoneErrorEl.textContent = '';
    phoneErrorEl.hidden = true;
  }
}

function filteredCountries() {
  const query = countryQuery.trim().toLowerCase();
  if (!query) return countries;
  const digits = query.replace(/^\+/, '');
  return countries.filter((country) => {
    const name = String(country.name || '').toLowerCase();
    const code = String(country.code || '').toLowerCase();
    const dial = formatDialCode(country.dial_code).toLowerCase();
    return name.includes(query) || code.includes(query) || dial.includes(query) || dial.includes(digits);
  });
}

function renderCountryMenu() {
  const list = filteredCountries();
  countryListEl.innerHTML = list.map((country) => {
    const code = country.flag?.code || country.code || '';
    const selected = country.id === selectedCountryId ? ' is-selected' : '';
    return `<li><button type="button" class="${selected.trim()}" data-id="${country.id}">
      <img class="phone-flag" alt="" src="${flagUrl(code)}">
      <span class="name">${escapeHtml(country.name || country.code)}</span>
      <span class="dial" dir="ltr">${escapeHtml(formatDialCode(country.dial_code))}</span>
    </button></li>`;
  }).join('');
  countryEmpty.hidden = list.length > 0;
}

function openCountryMenu() {
  closeLanguageMenu();
  countryQuery = '';
  countrySearch.value = '';
  menuEl.hidden = false;
  menuEl.setAttribute('dir', document.documentElement.dir || 'rtl');
  renderCountryMenu();
  setTimeout(() => countrySearch.focus(), 0);
}

dialBtn.addEventListener('click', () => {
  if (!countries.length) return;
  if (menuEl.hidden) openCountryMenu();
  else menuEl.hidden = true;
});

countrySearch.addEventListener('input', () => {
  countryQuery = countrySearch.value;
  renderCountryMenu();
});

countrySearch.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') menuEl.hidden = true;
});

menuEl.addEventListener('click', (event) => {
  const button = event.target.closest('button[data-id]');
  if (!button) return;
  const country = countries.find((item) => String(item.id) === button.dataset.id);
  if (country) applyCountry(country);
  menuEl.hidden = true;
});

document.addEventListener('click', (event) => {
  if (!event.target.closest('.phone-code-wrap')) menuEl.hidden = true;
  const profileMenu = $('#profile-phone-menu');
  if (profileMenu && !event.target.closest('#profile-phone-wrap')) profileMenu.hidden = true;
  if (!event.target.closest('.login-lang')) closeLanguageMenu();
});

function closeLanguageMenu() {
  langMenuEls.forEach((menu) => { menu.hidden = true; });
  langBtns.forEach((btn) => btn.setAttribute('aria-expanded', 'false'));
}

function renderLanguageMenu() {
  const html = languages.map((language) => {
    const code = String(language.code || '').toLowerCase();
    const selected = code === selectedLanguageCode ? ' is-selected' : '';
    return `<li role="option"><button type="button" class="${selected.trim()}" data-code="${escapeHtml(code)}">${escapeHtml(language.name || code)}</button></li>`;
  }).join('');
  langMenuEls.forEach((menu) => { menu.innerHTML = html; });
}

function paintOtpCopy() {
  const copy = LOGIN_COPY[selectedLanguageCode] || LOGIN_COPY.ar;
  const otpTitle = $('#otp-title');
  const otpPrefix = $('#otp-subtitle-prefix');
  const verifyLabel = $('.btn-label', $('#btn-verify-otp'));
  const resendBtn = $('#otp-resend-btn');
  if (otpTitle) otpTitle.textContent = copy.otpTitle;
  if (otpPrefix) otpPrefix.textContent = copy.otpSubtitle;
  if (verifyLabel) verifyLabel.textContent = copy.otpConfirm;
  if (resendBtn) resendBtn.textContent = copy.otpResend;
}

function applyLanguage(language) {
  const code = String(language.code || 'ar').toLowerCase();
  const localeChanged = apiHeaders['X-Locale'] !== code;
  selectedLanguageCode = code;
  persistLanguage(code);
  document.documentElement.lang = code;
  document.documentElement.dir = String(language.direction || '').toLowerCase() === 'ltr' ? 'ltr' : 'rtl';
  langNameEls.forEach((el) => { el.textContent = language.name || code; });
  apiHeaders['X-Locale'] = code;
  const copy = LOGIN_COPY[code] || LOGIN_COPY.ar;
  $('#login-title').textContent = copy.title;
  $('#login-subtitle').textContent = copy.subtitle;
  $('#login-terms-text').textContent = copy.terms;
  $('.btn-label', sendBtn).textContent = copy.send;
  countrySearch.placeholder = copy.search;
  countrySearch.setAttribute('aria-label', copy.search);
  countryEmpty.textContent = copy.empty;
  paintOtpCopy();
  paintBrandName();
  renderLanguageMenu();
  refreshLanguageDialogIfOpen();
  paintWholeAppCopy();
  if (localeChanged) {
    loadLanguages();
    loadCountries();
    syncPhoneError();
  }
}

// Re-paints every static label the moment the language flips — the web
// analogue of Compose's LocalizedApp recomposing the whole subtree.
function paintWholeAppCopy() {
  paintMainScreenCopy();
  paintProfileMenu(false);
  paintSubScreenIfOpen();
  paintOpenSheet();
  paintOpenDialog();
  paintNotifications();
  paintDeviceLabel();
}

function paintSubScreenIfOpen() {
  if (subScreenEl.classList.contains('open') && subStack.length) paintSubScreen();
}

function paintOpenSheet() {
  if (sheetBackdrop.classList.contains('open') && sheetOpenType) {
    const cfg = SHEETS[sheetOpenType];
    if (cfg) {
      sheetPanel.innerHTML = cfg.render();
      cfg.afterRender?.(sheetPanel);
    }
  }
}

function paintOpenDialog() {
  if (dialogBackdrop.classList.contains('open') && dialogOpenType) {
    const cfg = DIALOGS[dialogOpenType];
    if (cfg) {
      dialogBox.innerHTML = cfg.render();
      $('[data-close]', dialogBox).addEventListener('click', closeDialog);
      cfg.afterRender?.(dialogBox);
    }
  }
}

function paintNotifications() {
  const open = notifScreenEl.classList.contains('open');
  if (open) renderNotifications();
}

function paintDeviceLabel() {
  const label = $('.device-label');
  if (!label) return;
  const prefix = label.childNodes[0];
  if (prefix) prefix.textContent = `${t('deviceLabel')} · `;
}

// Localizes the static home/account markup (banner, shortcuts, tabs, account
// header stats and links). Elements carry data-i18n / data-i18n-label keys.
function paintMainScreenCopy() {
  $$('[data-i18n]').forEach((el) => {
    el.textContent = t(el.dataset.i18n);
  });
  $$('[data-i18n-ph]').forEach((el) => {
    el.placeholder = t(el.dataset.i18nPh);
  });
  $$('[data-i18n-aria]').forEach((el) => {
    el.setAttribute('aria-label', t(el.dataset.i18nAria));
  });
  const greeting = $('#home-greeting');
  if (greeting && profileState) greeting.textContent = `${t('hello')}، ${displayName()}`;
}

function loadLanguages() {
  fetch('/api/general/v1/languages/dropdown', { headers: apiHeaders })
    .then((r) => r.json())
    .then((res) => {
      languages = Array.isArray(res?.data) ? res.data : [];
      const chosen = languages.find((item) => String(item.code).toLowerCase() === selectedLanguageCode)
        || languages.find((item) => String(item.code).toLowerCase() === 'ar')
        || languages[0];
      if (chosen) applyLanguage(chosen);
      else {
        renderLanguageMenu();
        refreshLanguageDialogIfOpen();
      }
    })
    .catch(() => {
      // Offline — keep the Arabic label already in the markup.
    });
}

langBtns.forEach((btn) => {
  btn.addEventListener('click', () => {
    if (!languages.length) return;
    menuEl.hidden = true;
    const menu = btn.parentElement.querySelector('.login-lang-menu');
    const willOpen = menu.hidden;
    closeLanguageMenu();
    if (willOpen) {
      menu.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
    }
  });
});

langMenuEls.forEach((menu) => {
  menu.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-code]');
    if (!button) return;
    const language = languages.find((item) => String(item.code).toLowerCase() === button.dataset.code);
    if (language) applyLanguage(language);
    closeLanguageMenu();
  });
});

// Restore the persisted language before any network call, so the whole app
// (login copy, direction, and every painted label) boots in the saved locale.
(function restoreBootLanguage() {
  const boot = storedLanguage();
  if (boot && String(boot).toLowerCase() !== 'ar') {
    selectedLanguageCode = String(boot).toLowerCase();
    document.documentElement.lang = selectedLanguageCode;
    document.documentElement.dir = selectedLanguageCode === 'en' ? 'ltr' : 'rtl';
    apiHeaders['X-Locale'] = selectedLanguageCode;
  }
})();

loadLanguages();
loadCountries();
loadBranding();

function paintBrandName() {
  const nameEl = $('#login-brand-name');
  const splashTitle = $('#splash-title');
  const splashFooter = $('#splash-footer');
  const copy = LOGIN_COPY[selectedLanguageCode] || LOGIN_COPY.ar;
  if (!appName) {
    nameEl.hidden = true;
    return;
  }
  nameEl.hidden = brandImageShown;
  nameEl.textContent = appName;
  splashTitle.textContent = appName;
  splashFooter.textContent = `${copy.powered} ${appName}`;
  document.title = appName;
}

function setBrandImage(slot, url) {
  if (!url || !slot) return false;
  slot.classList.add('has-image');
  slot.innerHTML = `<img src="${escapeHtml(url)}" alt="${escapeHtml(appName)}">`;
  return true;
}

function loadBranding() {
  fetch('/api/general/v1/platform-settings/branding', { headers: apiHeaders })
    .then((r) => r.json())
    .then((res) => {
      const branding = res?.data;
      if (!branding) return;
      appName = String(branding.app_name || '').trim();
      paintBrandName();
      const mark = branding.apple_touch_icon || branding.logo || branding.logo_dark || branding.favicon_32;
      brandImageShown = setBrandImage($('#login-brand'), mark);
      setBrandImage($('#splash-logo'), mark);
      if (brandImageShown) $('#login-brand-name').hidden = true;
      const favicon = branding.favicon_ico || branding.favicon_32 || branding.favicon_16;
      if (favicon) {
        let icon = document.querySelector('link[rel="icon"]');
        if (!icon) {
          icon = document.createElement('link');
          icon.rel = 'icon';
          document.head.appendChild(icon);
        }
        icon.href = favicon;
      }
    })
    .catch(() => {
      // Branding is optional — the login screen keeps its fallback icon.
    });
}

function loadCountries() {
  const headers = { ...apiHeaders };
  return fetch('/api/general/v1/countries/dropdown', { headers })
    .then((r) => r.json())
    .then((res) => {
      const listed = Array.isArray(res?.data) ? res.data : [];
      if (listed.length) countries = listed;
      const chosen = countries.find((item) => item.is_default) || countries[0];
      if (chosen) applyCountry(chosen);
      else renderCountryMenu();
    })
    .catch(() => {
      // Offline or backend unreachable — keep the Saudi default above.
    });
}

phoneInput.addEventListener('input', () => {
  phoneInput.value = phoneInput.value.replace(/\D/g, '').slice(0, phoneLength);
  syncSendEnabled();
  syncPhoneError();
});

termsCheck.addEventListener('change', syncSendEnabled);

sendBtn.addEventListener('click', () => {
  sendBtn.disabled = true;
  $('.btn-label', sendBtn).hidden = true;
  $('.btn-spinner', sendBtn).hidden = false;
  setTimeout(() => {
    $('.btn-label', sendBtn).hidden = false;
    $('.btn-spinner', sendBtn).hidden = true;
    $('#otp-phone-target').textContent = `${dialCode} ${phoneInput.value}`;
    resetOtpBoxes();
    startOtpTimer();
    showScreen('otp');
  }, 500);
});

// ===================== OTP (6 individual digit boxes) =====================
const otpBoxesEl = $('#otp-boxes');
const otpBoxes = $$('.otp-box', otpBoxesEl);
const verifyBtn = $('#btn-verify-otp');
const otpTimerEl = $('#otp-timer');
const otpCountdownEl = $('#otp-countdown');
const otpTimerLabel = $('#otp-timer-label');
const otpTimerProgress = $('#otp-timer-progress');
const otpResendBtn = $('#otp-resend-btn');
const DEV_FIXED_OTP = '123456'; // real SMS isn't wired up in the backend yet — also the reference app's own offline-demo fallback
const OTP_RING_CIRCUMFERENCE = 157;
let otpTimerInterval = null;

function currentOtpCode() {
  return otpBoxes.map((b) => b.value).join('');
}

function resetOtpBoxes() {
  otpBoxesEl.classList.remove('error', 'success', 'shake');
  otpBoxes.forEach((b) => { b.value = ''; b.classList.remove('filled'); });
  verifyBtn.disabled = true;
  otpBoxes[0].focus();
}

otpBoxes.forEach((box, i) => {
  box.addEventListener('input', () => {
    box.value = box.value.replace(/\D/g, '').slice(0, 1);
    otpBoxesEl.classList.remove('error', 'success');
    if (box.value) {
      box.classList.add('filled');
      setTimeout(() => box.classList.remove('filled'), 240);
      if (i < otpBoxes.length - 1) {
        otpBoxes[i + 1].focus();
      } else {
        box.blur();
        verifyOtp();
      }
    }
    verifyBtn.disabled = currentOtpCode().length !== otpBoxes.length;
  });

  box.addEventListener('keydown', (e) => {
    if (e.key === 'Backspace' && !box.value && i > 0) {
      otpBoxes[i - 1].focus();
      otpBoxes[i - 1].value = '';
    }
  });
});

function startOtpTimer() {
  clearInterval(otpTimerInterval);
  let countdown = 60;
  otpTimerEl.hidden = false;
  otpResendBtn.hidden = true;
  otpCountdownEl.textContent = countdown;
  otpTimerLabel.textContent = (LOGIN_COPY[selectedLanguageCode] || LOGIN_COPY.ar).otpTimer.replace('{n}', countdown);
  otpTimerProgress.style.strokeDashoffset = 0;

  otpTimerInterval = setInterval(() => {
    countdown--;
    otpCountdownEl.textContent = countdown;
    otpTimerLabel.textContent = (LOGIN_COPY[selectedLanguageCode] || LOGIN_COPY.ar).otpTimer.replace('{n}', countdown);
    otpTimerProgress.style.strokeDashoffset = OTP_RING_CIRCUMFERENCE * (1 - countdown / 60);
    if (countdown <= 0) {
      clearInterval(otpTimerInterval);
      otpTimerEl.hidden = true;
      otpResendBtn.hidden = false;
    }
  }, 1000);
}

otpResendBtn.addEventListener('click', () => {
  resetOtpBoxes();
  startOtpTimer();
  // Real resend-otp call would go here once the backend endpoint exists.
});

$('#otp-back-btn').addEventListener('click', () => {
  clearInterval(otpTimerInterval);
  // Compose's declarative state would recompute this automatically; here we
  // have to re-sync it by hand since sendBtn.disabled was frozen on send.
  syncSendEnabled();
  showScreen('login');
});

function verifyOtp() {
  const code = currentOtpCode();
  if (code.length !== otpBoxes.length) return;
  verifyBtn.disabled = true;
  $('.btn-label', verifyBtn).hidden = true;
  $('.btn-spinner', verifyBtn).hidden = false;
  setTimeout(() => {
    $('.btn-label', verifyBtn).hidden = false;
    $('.btn-spinner', verifyBtn).hidden = true;
    if (code === DEV_FIXED_OTP) {
      otpBoxesEl.classList.add('success');
      clearInterval(otpTimerInterval);
      setTimeout(() => {
        const contact = $('#profile-contact');
        if (contact) contact.textContent = `${dialCode} ${phoneInput.value}`;
        showScreen('main');
        switchTab('home');
      }, 300);
    } else {
      otpBoxesEl.classList.add('error', 'shake');
      setTimeout(() => otpBoxesEl.classList.remove('shake'), 400);
      verifyBtn.disabled = false;
    }
  }, 400);
}

verifyBtn.addEventListener('click', verifyOtp);

// ===================== Bottom-tab shell =====================
const panels = $$('.tab-panel');
const tabBtns = $$('.tab-btn');

function switchTab(name) {
  panels.forEach((p) => p.classList.toggle('active', p.dataset.panel === name));
  tabBtns.forEach((b) => b.classList.toggle('active', b.dataset.tab === name));
  if (name === 'account') renderProfileMenu();
}

tabBtns.forEach((b) => b.addEventListener('click', () => switchTab(b.dataset.tab)));
$$('[data-goto-tab]').forEach((el) =>
  el.addEventListener('click', () => switchTab(el.dataset.gotoTab)),
);

// ===================== Hero banner auto-scroll =====================
const track = $('#banner-track');
const dotsEl = $('#banner-dots');
const slideCount = $$('.banner-slide', track).length;
let bannerIndex = 0;

for (let i = 0; i < slideCount; i++) {
  const dot = document.createElement('span');
  if (i === 0) dot.classList.add('active');
  dotsEl.appendChild(dot);
}

function goToSlide(i) {
  bannerIndex = i;
  track.style.transform = `translateX(${-i * 100}%)`;
  $$('span', dotsEl).forEach((d, idx) => d.classList.toggle('active', idx === i));
}

if (track && slideCount) {
  goToSlide(0);
  setInterval(() => {
    goToSlide((bannerIndex + 1) % slideCount);
  }, 5000);
}

// ===================== Profile menu (staggered slide-in) =====================
const ICONS = {
  person: 'icon-person', bell: 'icon-bell',
  language: 'icon-language', 'dark-mode': 'icon-dark-mode', help: 'icon-help',
  phone: 'icon-phone', info: 'icon-info', shield: 'icon-shield',
  logout: 'icon-logout', delete: 'icon-delete',
};

const SETTINGS_ITEMS = [
  { icon: 'person', action: 'personal-data', title: 'setPersonalTitle', subtitle: 'setPersonalSub' },
  { icon: 'bell', action: 'notifications', title: 'setNotifTitle', subtitle: 'setNotifSub' },
  { icon: 'language', action: 'language', title: 'setLangTitle', subtitle: 'setLangSub' },
  { icon: 'dark-mode', switch: true, title: 'setDarkTitle', subtitle: 'setDarkSub' },
  { icon: 'help', action: 'faq', title: 'setFaqTitle', subtitle: 'setFaqSub' },
  { icon: 'phone', action: 'contact', title: 'setContactTitle', subtitle: 'setContactSub' },
  { icon: 'info', action: 'about', title: 'setAboutTitle', subtitle: 'setAboutSub' },
  { icon: 'shield', action: 'privacy', title: 'setPrivacyTitle', subtitle: 'setPrivacySub' },
  { icon: 'logout', action: 'logout', title: 'setLogoutTitle', subtitle: 'setLogoutSub', danger: true },
  { icon: 'delete', action: 'delete', title: 'setDeleteTitle', subtitle: 'setDeleteSub', danger: true },
];

function settingsMenuHtml() {
  return SETTINGS_ITEMS.map((item) => {
    const iconId = ICONS[item.icon] || 'icon-info';
    const danger = item.danger ? ' account-link--danger' : '';
    const switchCls = item.switch ? ' account-link--switch' : '';
    const trail = item.switch
      ? '<span class="toggle" role="switch" aria-checked="false"></span>'
      : '<svg class="menu-chevron" viewBox="0 0 24 24"><use href="#icon-chevron"/></svg>';
    const actionAttr = item.action ? ` data-action="${item.action}"` : '';
    const switchAttr = item.switch ? ' data-switch="1"' : '';
    return `
      <button type="button" class="account-link${danger}${switchCls}"${actionAttr}${switchAttr}>
        <span class="account-link-icon"><svg viewBox="0 0 24 24"><use href="#${iconId}"/></svg></span>
        <span class="account-link-text"><strong>${t(item.title)}</strong><small>${t(item.subtitle)}</small></span>
        ${trail}
      </button>`;
  }).join('');
}

let profileRendered = false;
function renderProfileMenu(force = false) {
  if (profileRendered && !force) return;
  profileRendered = true;
  const menu = $('#profile-menu');
  menu.innerHTML = settingsMenuHtml();
  bindMenuRows(menu);
  if (force) bindMenuRows(menu);
}

function bindMenuRows(menu) {
  $$('[data-switch="1"]', menu).forEach((row) => {
    row.addEventListener('click', (event) => {
      event.preventDefault();
      const toggle = $('.toggle', row);
      toggle.classList.toggle('on');
      toggle.setAttribute('aria-checked', toggle.classList.contains('on') ? 'true' : 'false');
    });
  });
  $$('[data-action]', menu).forEach((row) => {
    row.addEventListener('click', () => handleProfileAction(row.dataset.action));
  });
}

// Repaints the in-page profile menu list with the current language.
function paintProfileMenu(force = false) {
  if (!profileRendered) return;
  const menu = $('#profile-menu');
  menu.innerHTML = settingsMenuHtml();
  bindMenuRows(menu);
}

function handleProfileAction(action) {
  switch (action) {
    case 'personal-data': return openSubScreen('personal-data');
    case 'notifications': return openSubScreen('notifications');
    case 'privacy': return openSubScreen('privacy');
    case 'faq': return openSheet('faq');
    case 'contact': return openSheet('contact');
    case 'about': return openDialog('about');
    case 'language': return openDialog('language');
    case 'logout':
    case 'delete': {
      const msg = action === 'logout'
        ? t('logoutConfirm')
        : t('deleteConfirm');
      if (confirm(msg)) {
        profileRendered = false;
        $('#profile-menu').innerHTML = '';
        showScreen('login');
        phoneInput.value = '';
        sendBtn.disabled = true;
      }
    }
  }
}

const accountPage = $('#account-page');
const accountSettings = $('#account-settings');

function openAccountSettings() {
  renderProfileMenu();
  accountPage.hidden = true;
  accountSettings.hidden = false;
}

$('#account-settings-btn').addEventListener('click', openAccountSettings);

$('#account-settings-back').addEventListener('click', () => {
  accountSettings.hidden = true;
  accountPage.hidden = false;
});

$('#wallet-add-btn').addEventListener('click', () => {
  showToast(t('walletDemo'));
});

$$('[data-account-link]').forEach((button) => {
  button.addEventListener('click', () => {
    const link = button.dataset.accountLink;
    if (link === 'personal-data') openSubScreen('personal-data');
    else if (link === 'settings') openAccountSettings();
    else if (link === 'help') openSheet('faq');
    else showToast(t('comingSoon'));
  });
});

// ===================== Sub-screens (Personal data / Notifications / Privacy) =====================
const subScreenEl = $('#sub-screen');
const subTitleEl = $('#sub-title');
const subBodyEl = $('#sub-body');
const subStack = [];
const profileState = {
  name: 'مستخدم زائر',
  gender: '',
  dial: '',
  flag: '',
  phoneLength: 0,
  countryId: null,
  phone: '',
  email: '',
};
const contactDraft = {
  kind: null,
  step: 'edit',
  dial: '',
  flag: '',
  phoneLength: 0,
  countryId: null,
  value: '',
};
let profileOtpTimer = null;

function genderLabel(value) {
  if (value === 'male') return t('genderMale');
  if (value === 'female') return t('genderFemale');
  return t('genderUnspecified');
}

function profilePhoneText() {
  if (!profileState.phone) return '';
  return `${profileState.dial || dialCode} ${profileState.phone}`;
}

function countryByDial(dial) {
  return countries.find((country) => formatDialCode(country.dial_code) === dial);
}

function ensureProfileCountry() {
  if (!profileState.dial) profileState.dial = dialCode;
  const matched = countryByDial(profileState.dial);
  if (matched) {
    profileState.countryId = matched.id;
    profileState.flag = matched.flag?.code || matched.code || flagCode;
    profileState.phoneLength = matched.phone_length || phoneLength;
    return;
  }
  if (!profileState.flag) profileState.flag = flagCode;
  if (!profileState.phoneLength) profileState.phoneLength = phoneLength;
  if (!profileState.countryId) profileState.countryId = selectedCountryId;
}

function syncProfilePhoneFromLogin() {
  if (!profileState.phone) {
    const stored = ($('#profile-contact')?.textContent || '').trim();
    const source = stored || (phoneInput.value ? `${dialCode} ${phoneInput.value}` : '');
    const match = source.match(/^(\+\d+)\s*(.*)$/);
    if (match) {
      profileState.dial = match[1];
      profileState.phone = match[2].replace(/\D/g, '');
    }
  }
  ensureProfileCountry();
}

function displayName() {
  return (!profileState.name || profileState.name === 'مستخدم زائر') ? t('guestUser') : profileState.name;
}

function paintProfileSurfaces() {
  const nameEl = $('#profile-name');
  if (nameEl) nameEl.textContent = displayName();
  const homeGreeting = $('#home-greeting');
  if (homeGreeting) homeGreeting.textContent = `${t('hello')}، ${displayName()}`;
  const contact = $('#profile-contact');
  if (contact) contact.textContent = profilePhoneText();
}

function clearProfileOtpTimer() {
  clearInterval(profileOtpTimer);
  profileOtpTimer = null;
}

const verifiedMark = `<svg class="profile-edit-check" viewBox="0 0 24 24" aria-label="${t('verified')}"><circle cx="12" cy="12" r="10"/><path d="M7.2 12.2l3.1 3.1 6.5-6.6"/></svg>`;

function profileOtpMarkup(target) {
  const boxes = Array.from({ length: 6 }, (_, i) =>
    `<input type="tel" class="otp-box profile-otp-box" maxlength="1" inputmode="numeric" autocomplete="one-time-code">`
  ).join('');
  return `
    <div class="profile-form">
      <div class="profile-form-card profile-otp">
        <p class="profile-form-hint">${t('otpHint', { target: `<bdi dir="ltr">${escapeHtml(target)}</bdi>` })}</p>
        <div class="otp-boxes" id="profile-otp-boxes" dir="ltr">${boxes}</div>
        <p class="profile-otp-timer" id="profile-otp-timer">${t('otpTimer', { n: '<span id="profile-otp-count">60</span>' })}</p>
        <button type="button" class="profile-otp-resend" id="profile-otp-resend" hidden>${t('otpResend')}</button>
        <p class="profile-dev-hint">${t('otpDevHint')}</p>
      </div>
      <button type="button" class="btn-primary" id="profile-otp-confirm" disabled><span class="btn-label">${t('confirmBtn')}</span></button>
    </div>`;
}

function startProfileOtpTimer(root) {
  clearProfileOtpTimer();
  let left = 60;
  const timer = $('#profile-otp-timer', root);
  const count = $('#profile-otp-count', root);
  const resend = $('#profile-otp-resend', root);
  timer.hidden = false;
  resend.hidden = true;
  count.textContent = String(left);
  profileOtpTimer = setInterval(() => {
    left -= 1;
    count.textContent = String(left);
    if (left <= 0) {
      clearProfileOtpTimer();
      timer.hidden = true;
      resend.hidden = false;
    }
  }, 1000);
}

function bindProfileOtp(root, onSuccess) {
  const boxes = $$('.profile-otp-box', root);
  const confirmBtn = $('#profile-otp-confirm', root);
  const wrap = $('#profile-otp-boxes', root);
  let busy = false;

  const code = () => boxes.map((box) => box.value).join('');
  const sync = () => { confirmBtn.disabled = code().length !== 6; };

  function verify() {
    if (busy || code().length !== 6) return;
    busy = true;
    confirmBtn.disabled = true;
    setTimeout(() => {
      if (code() === DEV_FIXED_OTP) {
        wrap.classList.add('success');
        onSuccess();
        return;
      }
      wrap.classList.add('error', 'shake');
      setTimeout(() => wrap.classList.remove('shake'), 400);
      busy = false;
      confirmBtn.disabled = false;
      showToast(t('toastWrongCode'));
    }, 280);
  }

  boxes.forEach((box, index) => {
    box.addEventListener('input', () => {
      box.value = box.value.replace(/\D/g, '').slice(0, 1);
      wrap.classList.remove('error', 'success');
      if (box.value && index < boxes.length - 1) boxes[index + 1].focus();
      sync();
      if (box.value && index === boxes.length - 1) verify();
    });
    box.addEventListener('keydown', (event) => {
      if (event.key === 'Backspace' && !box.value && index > 0) {
        boxes[index - 1].focus();
        boxes[index - 1].value = '';
        sync();
      }
    });
  });

  confirmBtn.addEventListener('click', verify);
  $('#profile-otp-resend', root).addEventListener('click', () => {
    boxes.forEach((box) => { box.value = ''; });
    wrap.classList.remove('error', 'success');
    confirmBtn.disabled = true;
    busy = false;
    startProfileOtpTimer(root);
    showToast(t('toastCodeSent'));
    boxes[0]?.focus();
  });
  startProfileOtpTimer(root);
  boxes[0]?.focus();
}

function finishContactEdit(message) {
  contactDraft.step = 'edit';
  clearProfileOtpTimer();
  paintProfileSurfaces();
  subStack.pop();
  showToast(message);
  if (subStack.length) paintSubScreen();
}

function beginContactEdit(kind) {
  ensureProfileCountry();
  contactDraft.kind = kind;
  contactDraft.step = 'edit';
  contactDraft.dial = profileState.dial || dialCode;
  contactDraft.flag = profileState.flag || flagCode;
  contactDraft.phoneLength = profileState.phoneLength || phoneLength;
  contactDraft.countryId = profileState.countryId || selectedCountryId;
  contactDraft.value = kind === 'phone' ? profileState.phone : profileState.email;
  clearProfileOtpTimer();
  openSubScreen(kind === 'phone' ? 'edit-phone' : 'edit-email', { nested: true });
}

function bindProfilePhone(root) {
  const input = $('#profile-phone-input', root);
  const menu = $('#profile-phone-menu', root);
  const listEl = $('#profile-country-list', root);
  const emptyEl = $('#profile-country-empty', root);
  const search = $('#profile-country-search', root);
  const dialTextEl = $('#profile-dial-text', root);
  const flagImg = $('#profile-flag', root);
  let query = '';

  function paintCountry() {
    dialTextEl.textContent = contactDraft.dial;
    flagImg.src = flagUrl(contactDraft.flag || flagCode);
    flagImg.alt = contactDraft.flag || flagCode;
    const length = contactDraft.phoneLength || phoneLength;
    input.maxLength = length;
    input.placeholder = '0'.repeat(length);
    input.value = input.value.replace(/\D/g, '').slice(0, length);
  }

  function renderMenu() {
    const q = query.trim().toLowerCase();
    const digits = q.replace(/^\+/, '');
    const list = countries.filter((country) => {
      if (!q) return true;
      const name = String(country.name || '').toLowerCase();
      const code = String(country.code || '').toLowerCase();
      const dial = formatDialCode(country.dial_code).toLowerCase();
      return name.includes(q) || code.includes(q) || dial.includes(q) || dial.includes(digits);
    });
    listEl.innerHTML = list.map((country) => {
      const code = country.flag?.code || country.code || '';
      const selected = country.id === contactDraft.countryId ? ' is-selected' : '';
      return `<li><button type="button" class="${selected.trim()}" data-id="${country.id}">
        <img class="phone-flag" alt="" src="${flagUrl(code)}">
        <span class="name">${escapeHtml(country.name || country.code)}</span>
        <span class="dial" dir="ltr">${escapeHtml(formatDialCode(country.dial_code))}</span>
      </button></li>`;
    }).join('');
    emptyEl.hidden = list.length > 0;
  }

  $('#profile-dial-btn', root).addEventListener('click', () => {
    if (!countries.length) return;
    if (menu.hidden) {
      query = '';
      search.value = '';
      menu.hidden = false;
      menu.setAttribute('dir', document.documentElement.dir || 'rtl');
      renderMenu();
      setTimeout(() => search.focus(), 0);
    } else {
      menu.hidden = true;
    }
  });

  search.addEventListener('input', () => {
    query = search.value;
    renderMenu();
  });
  search.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') menu.hidden = true;
  });
  menu.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-id]');
    if (!button) return;
    const country = countries.find((item) => String(item.id) === button.dataset.id);
    if (!country) return;
    contactDraft.countryId = country.id;
    contactDraft.flag = country.flag?.code || country.code || contactDraft.flag;
    contactDraft.dial = formatDialCode(country.dial_code) || contactDraft.dial;
    contactDraft.phoneLength = country.phone_length || contactDraft.phoneLength;
    paintCountry();
    menu.hidden = true;
  });
  input.addEventListener('input', () => {
    input.value = input.value.replace(/\D/g, '').slice(0, contactDraft.phoneLength || phoneLength);
  });
}

const SUB_SCREENS = {
  'personal-data': {
    title: () => t('pdTitle'),
    render: () => {
      const phone = profilePhoneText();
      const email = profileState.email;
      const field = (key, icon, label, value, verified) => `
        <button type="button" class="account-link profile-field" data-field="${key}">
          <span class="account-link-icon">${icon}</span>
          <span class="account-link-text"><strong>${label}</strong><small>${value}${verified ? verifiedMark : ''}</small></span>
          <svg class="menu-chevron" viewBox="0 0 24 24"><use href="#icon-chevron"/></svg>
        </button>`;
      return `
        <div class="profile-edit">
          <div class="profile-edit-avatar" aria-hidden="true">
            <svg viewBox="0 0 24 24"><use href="#icon-person"/></svg>
            <span class="profile-edit-pen">
              <svg viewBox="0 0 24 24"><path d="M4 20l4.2-1.1L19 8.1a2 2 0 0 0-2.8-2.8L5.4 16.1z"/><path d="M14.5 6.8l2.7 2.7"/></svg>
            </span>
          </div>
          <div class="account-links profile-fields">
            ${field('name', '<svg viewBox="0 0 24 24"><use href="#icon-person"/></svg>', t('pdName'), escapeHtml(profileState.name), false)}
            ${field('gender', '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.2"/><path d="M6.5 19.5c.4-2.8 2.6-4.8 5.5-4.8s5.1 2 5.5 4.8"/></svg>', t('pdGender'), escapeHtml(genderLabel(profileState.gender)), false)}
            ${field('phone', '<svg viewBox="0 0 24 24"><use href="#icon-phone"/></svg>', t('pdPhone'), phone ? `<span dir="ltr">${escapeHtml(phone)}</span>` : t('pdNotAdded'), Boolean(phone))}
            ${field('email', '<svg viewBox="0 0 24 24"><use href="#icon-email"/></svg>', t('pdEmail'), email ? escapeHtml(email) : t('pdNotAdded'), Boolean(email))}
          </div>
        </div>`;
    },
    afterRender: (root) => {
      $$('.profile-field', root).forEach((row) => {
        row.addEventListener('click', () => {
          const field = row.dataset.field;
          if (field === 'name') openSubScreen('edit-name', { nested: true });
          else if (field === 'gender') openSubScreen('edit-gender', { nested: true });
          else if (field === 'phone') beginContactEdit('phone');
          else if (field === 'email') beginContactEdit('email');
        });
      });
    },
  },
  'edit-name': {
    title: () => t('editNameTitle'),
    render: () => `
      <div class="profile-form">
        <div class="profile-form-card">
          <label class="profile-field-label" for="profile-name-input">${t('editNameLabel')}</label>
          <div class="profile-icon-field">
            <span class="profile-input-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><use href="#icon-person"/></svg></span>
            <input id="profile-name-input" type="text" maxlength="80" value="${escapeHtml(profileState.name)}">
          </div>
          <p class="profile-form-hint">${t('editNameHint')}</p>
        </div>
        <button type="button" class="btn-primary" id="profile-save-name"><span class="btn-label">${t('save')}</span></button>
      </div>`,
    afterRender: (root) => {
      const input = $('#profile-name-input', root);
      $('#profile-save-name', root).addEventListener('click', () => {
        const value = input.value.trim();
        if (value.length < 2) {
          showToast(t('toastEnterName'));
          input.focus();
          return;
        }
        profileState.name = value;
        paintProfileSurfaces();
        showToast(t('toastNameUpdated'));
        closeSubScreen();
      });
      input.focus();
    },
  },
  'edit-gender': {
    title: () => t('editGenderTitle'),
    render: () => {
      const icons = {
        male: '<circle cx="10" cy="14.5" r="5"/><path d="M13.6 10.9L19 5.5M15.2 5.5H19V9.3"/>',
        female: '<circle cx="12" cy="8.5" r="5"/><path d="M12 13.5V20M9 17.2h6"/>',
      };
      const option = (value, label) => `
        <button type="button" class="gender-option${profileState.gender === value ? ' selected' : ''}" data-gender="${value}">
          <span class="gender-icon" aria-hidden="true"><svg viewBox="0 0 24 24">${icons[value]}</svg></span>
          <span>${label}</span>
          <span class="dot" aria-hidden="true"></span>
        </button>`;
      return `
        <div class="profile-form">
          <div class="profile-form-card">
            <p class="profile-field-label">${t('pdGender')}</p>
            <div class="gender-options">
              ${option('male', t('genderMale'))}
              ${option('female', t('genderFemale'))}
            </div>
            <p class="profile-form-hint">${t('editGenderHint')}</p>
          </div>
          <button type="button" class="btn-primary" id="profile-save-gender"><span class="btn-label">${t('save')}</span></button>
        </div>`;
    },
    afterRender: (root) => {
      let selected = profileState.gender;
      $$('.gender-option', root).forEach((option) => {
        option.addEventListener('click', () => {
          selected = option.dataset.gender;
          $$('.gender-option', root).forEach((item) => item.classList.toggle('selected', item === option));
        });
      });
      $('#profile-save-gender', root).addEventListener('click', () => {
        if (!selected) {
          showToast(t('toastPickGender'));
          return;
        }
        profileState.gender = selected;
        showToast(t('toastGenderUpdated'));
        closeSubScreen();
      });
    },
  },
  'edit-phone': {
    title: () => t('editPhoneTitle'),
    render: () => {
      if (contactDraft.step === 'otp') {
        return profileOtpMarkup(`${contactDraft.dial} ${contactDraft.value}`);
      }
      const length = contactDraft.phoneLength || phoneLength || 9;
      const flag = contactDraft.flag || flagCode || 'sa';
      const dial = contactDraft.dial || dialCode;
      return `
        <div class="profile-form">
          <div class="profile-form-card">
          <label class="profile-field-label" for="profile-phone-input">${t('editPhoneLabel')}</label>
          <div class="phone-field">
            <span class="phone-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M7 3.5h2.2l1.2 3-1.7 1a11 11 0 0 0 5.8 5.8l1-1.7 3 1.2V15a2 2 0 0 1-2.2 2A13.5 13.5 0 0 1 5 7.7 2 2 0 0 1 7 3.5z"/></svg>
            </span>
            <span class="phone-sep"></span>
            <div class="phone-code-wrap" id="profile-phone-wrap">
              <button type="button" class="phone-code" id="profile-dial-btn" aria-haspopup="listbox" aria-label="${t('phoneCodeLabel')}">
                <img class="phone-flag" id="profile-flag" alt="" width="20" height="15" src="${flagUrl(flag)}">
                <span id="profile-dial-text">${escapeHtml(dial)}</span>
                <span class="phone-code-caret" aria-hidden="true"></span>
              </button>
              <div class="phone-code-menu" id="profile-phone-menu" hidden>
                <label class="phone-code-search">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                  <input type="search" id="profile-country-search" placeholder="${t('countrySearchPlaceholder')}" autocomplete="off" aria-label="${t('countrySearchPlaceholder')}">
                </label>
                <ul id="profile-country-list"></ul>
                <p class="phone-code-empty" id="profile-country-empty" hidden>${t('countrySearchEmpty')}</p>
              </div>
            </div>
            <span class="phone-sep"></span>
            <input type="tel" id="profile-phone-input" maxlength="${length}" placeholder="${'0'.repeat(length)}" inputmode="numeric" value="${escapeHtml(contactDraft.value || '')}">
          </div>
          <p class="profile-form-hint">${t('editPhoneHint')}</p>
          </div>
          <button type="button" class="btn-primary" id="profile-send-code"><span class="btn-label">${t('sendCode')}</span></button>
        </div>`;
    },
    afterRender: (root) => {
      if (contactDraft.step === 'otp') {
        bindProfileOtp(root, () => {
          profileState.dial = contactDraft.dial;
          profileState.flag = contactDraft.flag;
          profileState.phoneLength = contactDraft.phoneLength;
          profileState.countryId = contactDraft.countryId;
          profileState.phone = contactDraft.value;
          finishContactEdit(t('toastPhoneUpdated'));
        });
        return;
      }
      bindProfilePhone(root);
      const input = $('#profile-phone-input', root);
      $('#profile-send-code', root).addEventListener('click', () => {
        const length = contactDraft.phoneLength || phoneLength;
        const digits = input.value.replace(/\D/g, '');
        if (digits.length !== length) {
          showToast(t('toastValidPhone'));
          input.focus();
          return;
        }
        contactDraft.value = digits;
        contactDraft.step = 'otp';
        paintSubScreen();
        showToast(t('toastCodeSent'));
      });
      input.focus();
    },
  },
  'edit-email': {
    title: () => t('editEmailTitle'),
    render: () => {
      if (contactDraft.step === 'otp') return profileOtpMarkup(contactDraft.value);
      return `
        <div class="profile-form">
          <div class="profile-form-card">
            <label class="profile-field-label" for="profile-email-input">${t('editEmailLabel')}</label>
            <div class="profile-icon-field">
              <span class="profile-input-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><use href="#icon-email"/></svg></span>
              <input id="profile-email-input" type="email" inputmode="email" maxlength="120" dir="ltr" value="${escapeHtml(profileState.email)}" placeholder="name@example.com">
            </div>
            <p class="profile-form-hint">${t('editEmailHint')}</p>
          </div>
          <button type="button" class="btn-primary" id="profile-send-email"><span class="btn-label">${t('sendCode')}</span></button>
        </div>`;
    },
    afterRender: (root) => {
      if (contactDraft.step === 'otp') {
        bindProfileOtp(root, () => {
          profileState.email = contactDraft.value;
          finishContactEdit(t('toastEmailUpdated'));
        });
        return;
      }
      const input = $('#profile-email-input', root);
      $('#profile-send-email', root).addEventListener('click', () => {
        const email = input.value.trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          showToast(t('toastValidEmail'));
          input.focus();
          return;
        }
        contactDraft.value = email;
        contactDraft.step = 'otp';
        paintSubScreen();
        showToast(t('toastCodeSent'));
      });
      input.focus();
    },
  },
  notifications: {
    title: () => t('notifTitle'),
    render: () => `
      <div class="profile-form">
        <div class="profile-form-card notif-settings">
          ${notifToggleRow('icon-bell', t('notifToggle1Title'), t('notifToggle1Desc'), true)}
          ${notifToggleRow('icon-receipt', t('notifToggle2Title'), t('notifToggle2Desc'), true)}
          ${notifToggleRow('icon-tag', t('notifToggle3Title'), t('notifToggle3Desc'), true)}
          ${notifToggleRow('icon-email', t('notifToggle4Title'), t('notifToggle4Desc'), false)}
        </div>
      </div>`,
afterRender: (root) => {
      $$('.toggle', root).forEach((tl) => {
        tl.addEventListener('click', (event) => {
          event.stopPropagation();
          tl.classList.toggle('on');
        });
      });
    },
  },
  privacy: {
    title: () => t('privacyTitle'),
    render: () => `
      <div class="profile-form">
        <div class="profile-form-card privacy-card">
          <div class="content-head">
            <span class="account-link-icon"><svg viewBox="0 0 24 24"><use href="#icon-shield"/></svg></span>
            <strong>${t('privacyHead')}</strong>
          </div>
          <p class="privacy-text">${t('privacyBody')}</p>
        </div>
      </div>`,
  },
};

function notifToggleRow(icon, title, desc, on) {
  return `
    <div class="toggle-setting">
      <span class="account-link-icon"><svg viewBox="0 0 24 24"><use href="#${icon}"/></svg></span>
      <div class="ts-text"><div class="ts-title">${title}</div><div class="ts-desc">${desc}</div></div>
      <span class="toggle ${on ? 'on' : ''}"></span>
    </div>
  `;
}

const PROFILE_SCREENS = new Set([
  'personal-data', 'edit-name', 'edit-gender', 'edit-phone', 'edit-email',
  'notifications', 'privacy',
]);

function paintSubScreen() {
  const type = subStack[subStack.length - 1];
  const cfg = SUB_SCREENS[type];
  if (!cfg) return;
  subTitleEl.textContent = typeof cfg.title === 'function' ? cfg.title() : cfg.title;
  subScreenEl.classList.toggle('is-profile', PROFILE_SCREENS.has(type));
  subBodyEl.innerHTML = cfg.render();
  cfg.afterRender?.(subBodyEl);
  subScreenEl.classList.add('open');
}

function openSubScreen(type, options = {}) {
  if (!options.nested) {
    subStack.length = 0;
    contactDraft.step = 'edit';
    clearProfileOtpTimer();
  }
  if (type === 'personal-data') syncProfilePhoneFromLogin();
  subStack.push(type);
  paintSubScreen();
}

function closeSubScreen() {
  const type = subStack[subStack.length - 1];
  if ((type === 'edit-phone' || type === 'edit-email') && contactDraft.step === 'otp') {
    contactDraft.step = 'edit';
    clearProfileOtpTimer();
    paintSubScreen();
    return;
  }
  clearProfileOtpTimer();
  subStack.pop();
  if (subStack.length) paintSubScreen();
  else subScreenEl.classList.remove('open', 'is-profile');
}
$('#sub-back-btn').addEventListener('click', closeSubScreen);

// ===================== Bottom sheets (FAQ / Contact us) =====================
const sheetBackdrop = $('#sheet-backdrop');
const sheetPanel = $('#sheet-panel');
let sheetOpenType = null;
let dialogOpenType = null;

const FAQ_ITEMS = [
  ['faq1q', 'faq1a'],
  ['faq2q', 'faq2a'],
  ['faq3q', 'faq3a'],
  ['faq4q', 'faq4a'],
];

const SHEETS = {
  faq: {
    render: () => `
      <div class="sheet-head">
        <span class="account-link-icon"><svg viewBox="0 0 24 24"><use href="#icon-help"/></svg></span>
        <h3>${t('faqTitle')}</h3>
      </div>
      <div class="sheet-list">
      ${FAQ_ITEMS.map(([qKey, aKey]) => `
        <div class="faq-item">
          <span class="account-link-icon faq-icon"><svg viewBox="0 0 24 24"><use href="#icon-help"/></svg></span>
          <div class="faq-copy">
            <div class="faq-q">${t(qKey)}</div>
            <div class="faq-a">${t(aKey)}</div>
          </div>
          <svg class="menu-chevron faq-chevron" viewBox="0 0 24 24"><use href="#icon-chevron"/></svg>
        </div>
      `).join('')}
      </div>
    `,
    afterRender: (root) => {
      $$('.faq-item', root).forEach((item) => item.addEventListener('click', () => item.classList.toggle('open')));
    },
  },
  contact: {
    render: () => `
      <div class="sheet-head">
        <span class="account-link-icon"><svg viewBox="0 0 24 24"><use href="#icon-phone"/></svg></span>
        <h3>${t('contactTitle')}</h3>
      </div>
      <div class="sheet-list">
      ${contactTile('icon-phone', t('contactCall'), '+96522200000', 'tel:+96522200000')}
      ${contactTile('icon-chat', t('contactWhatsapp'), '96522200000', 'https://wa.me/96522200000')}
      ${contactTile('icon-email', t('contactEmail'), 'support@dorr.app', 'mailto:support@dorr.app')}
      </div>
    `,
  },
};

function contactTile(icon, label, value, href) {
  return `
    <a class="contact-tile" href="${href}" target="_blank" rel="noopener">
      <span class="account-link-icon"><svg viewBox="0 0 24 24"><use href="#${icon}"/></svg></span>
      <span class="ct-copy"><span class="ct-label">${label}</span><span class="ct-value">${value}</span></span>
      <svg class="menu-chevron" viewBox="0 0 24 24"><use href="#icon-chevron"/></svg>
    </a>
  `;
}

function openSheet(type) {
  const cfg = SHEETS[type];
  sheetOpenType = type;
  sheetPanel.className = 'sheet-panel app-sheet';
  sheetPanel.innerHTML = cfg.render();
  cfg.afterRender?.(sheetPanel);
  sheetBackdrop.classList.add('open');
}
function closeSheet() {
  sheetBackdrop.classList.remove('open');
}
sheetBackdrop.addEventListener('click', (e) => {
  if (e.target === sheetBackdrop) closeSheet();
});

// ===================== Dialogs (About / Language) =====================
const dialogBackdrop = $('#dialog-backdrop');
const dialogBox = $('#dialog-box');

const DIALOGS = {
  about: {
    render: () => `
      <div class="dialog-head">
        <span class="account-link-icon"><svg viewBox="0 0 24 24"><use href="#icon-info"/></svg></span>
        <h3>${appName}</h3>
      </div>
      <p>${t('aboutVersion')}<br><br>${t('aboutBody')}</p>
      <button class="dialog-close" data-close>${t('closeLabel')}</button>
    `,
  },
  language: {
    render: () => renderLanguageDialogMarkup(),
    afterRender: (root) => bindLanguageDialog(root),
  },
};

function renderLanguageDialogMarkup() {
  const head = `
    <div class="dialog-head">
      <span class="account-link-icon"><svg viewBox="0 0 24 24"><use href="#icon-language"/></svg></span>
      <h3>${t('langDialogTitle')}</h3>
    </div>`;
  if (!languages.length) {
    return `${head}
      <p class="lang-loading">${t('langLoading')}</p>
      <button class="dialog-close" data-close>${t('closeLabel')}</button>`;
  }
  const options = languages.map((language) => {
    const code = String(language.code || '').toLowerCase();
    const checked = code === selectedLanguageCode ? ' checked' : '';
    const fc = languageFlagCode(language);
    return `
      <label class="lang-option">
        <span class="account-link-icon lang-flag lang-flag-img">
          <img class="phone-flag" alt="" width="24" height="18" src="${flagUrl(fc)}">
        </span>
        <span>${escapeHtml(language.name || code)}</span>
        <input type="radio" name="lang" value="${escapeHtml(code)}"${checked}>
      </label>`;
  }).join('');
  return `${head}
    <div class="lang-options">${options}</div>
    <button class="dialog-close" data-close>${t('closeLabel')}</button>`;
}

function bindLanguageDialog(root) {
  $$('input[name="lang"]', root).forEach((input) => {
    input.addEventListener('change', () => {
      const language = languages.find((item) => String(item.code).toLowerCase() === input.value);
      if (language) applyLanguage(language);
    });
  });
}

function refreshLanguageDialogIfOpen() {
  if (!dialogBackdrop.classList.contains('open')) return;
  const cfg = DIALOGS.language;
  if (!cfg) return;
  dialogBox.innerHTML = cfg.render();
  $('[data-close]', dialogBox).addEventListener('click', closeDialog);
  cfg.afterRender?.(dialogBox);
}

function openDialog(type) {
  const cfg = DIALOGS[type];
  dialogOpenType = type;
  dialogBox.className = 'dialog-box app-dialog';
  dialogBox.innerHTML = cfg.render();
  $('[data-close]', dialogBox).addEventListener('click', closeDialog);
  cfg.afterRender?.(dialogBox);
  dialogBackdrop.classList.add('open');
  if (type === 'language' && !languages.length) {
    fetch('/api/general/v1/languages/dropdown', { headers: apiHeaders })
      .then((r) => r.json())
      .then((res) => {
        languages = Array.isArray(res?.data) ? res.data : [];
        refreshLanguageDialogIfOpen();
      })
      .catch(() => {
        dialogBox.querySelector('.lang-loading').textContent = t('langLoadFail');
      });
  }
}
function closeDialog() {
  dialogBackdrop.classList.remove('open');
}
dialogBackdrop.addEventListener('click', (e) => {
  if (e.target === dialogBackdrop) closeDialog();
});

// ===================== Toast =====================
const toastEl = $('#toast');
let toastTimer;
function showToast(message) {
  toastEl.textContent = message;
  toastEl.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toastEl.classList.remove('show'), 1800);
}

// ===================== Notifications feed (bell icon on Home) =====================
// Placeholder feed, like the Kotlin screen — wire to a real notifications
// endpoint later (pagination + push-triggered refresh).
const NOTIF_TYPE_STYLE = {
  job_update: { icon: 'icon-build', color: 'var(--info)' },
  quote: { icon: 'icon-description', color: 'var(--warning)' },
  invoice: { icon: 'icon-receipt', color: 'var(--secondary)' },
  maintenance: { icon: 'icon-event', color: 'var(--accent)' },
  promo: { icon: 'icon-tag', color: 'var(--danger)' },
  general: { icon: 'icon-bell', color: 'var(--primary)' },
};

let notifications = [
  { id: '1', type: 'job_update', titleKey: 'notif1Title', bodyKey: 'notif1Body', minutesAgo: 5, unread: true },
  { id: '2', type: 'quote', titleKey: 'notif2Title', bodyKey: 'notif2Body', minutesAgo: 40, unread: true },
  { id: '3', type: 'invoice', titleKey: 'notif3Title', bodyKey: 'notif3Body', minutesAgo: 180, unread: false },
  { id: '4', type: 'maintenance', titleKey: 'notif4Title', bodyKey: 'notif4Body', minutesAgo: 1500, unread: false },
  { id: '5', type: 'promo', titleKey: 'notif5Title', bodyKey: 'notif5Body', minutesAgo: 4000, unread: false },
  { id: '6', type: 'general', titleKey: 'notif6Title', bodyKey: 'notif6Body', minutesAgo: 10000, unread: false },
];

function notifRelativeTime(minutesAgo) {
  if (minutesAgo < 1) return t('notifNow');
  if (minutesAgo < 60) return t('notifMin', { n: minutesAgo });
  if (minutesAgo < 24 * 60) return t('notifHour', { n: Math.floor(minutesAgo / 60) });
  if (minutesAgo < 2 * 24 * 60) return t('notifDay');
  if (minutesAgo < 7 * 24 * 60) return t('notifDays', { n: Math.floor(minutesAgo / (24 * 60)) });
  return t('notifWeeks', { n: Math.floor(minutesAgo / (7 * 24 * 60)) });
}

const notifScreenEl = $('#notifications-screen');
const notifListEl = $('#notif-list');
const notifMarkAllBtn = $('#notif-mark-all-btn');
const bellDotEl = $('.home-bell-dot');

function renderNotifications() {
  const hasUnread = notifications.some((n) => n.unread);
  notifMarkAllBtn.hidden = !hasUnread;
  bellDotEl.hidden = !hasUnread;

  if (notifications.length === 0) {
    notifListEl.innerHTML = `
      <div class="notif-empty">
        <div class="notif-empty-icon"><svg viewBox="0 0 24 24"><use href="#icon-bell"/></svg></div>
        <h3>${t('notifEmptyTitle')}</h3>
        <p>${t('notifEmptySub')}</p>
      </div>
    `;
    return;
  }

  notifListEl.innerHTML = notifications.map((n) => {
    const style = NOTIF_TYPE_STYLE[n.type] || NOTIF_TYPE_STYLE.general;
    return `
      <div class="notif-card ${n.unread ? 'unread' : ''}" data-id="${n.id}">
        <div class="notif-card-icons">
          <span class="notif-dot" style="visibility:${n.unread ? 'visible' : 'hidden'}"></span>
          <span class="notif-type-icon" style="background:color-mix(in srgb, ${style.color} 10%, transparent); color:${style.color}">
            <svg viewBox="0 0 24 24"><use href="#${style.icon}"/></svg>
          </span>
        </div>
        <div class="notif-card-body">
          <div class="notif-card-title-row">
            <span class="notif-card-title">${t(n.titleKey)}</span>
            <span class="notif-card-time">${notifRelativeTime(n.minutesAgo)}</span>
          </div>
          <p class="notif-card-excerpt">${t(n.bodyKey)}</p>
        </div>
      </div>
    `;
  }).join('');

  $$('.notif-card', notifListEl).forEach((card) => {
    card.addEventListener('click', () => {
      const id = card.dataset.id;
      const item = notifications.find((n) => n.id === id);
      if (!item) return;
      item.unread = false;
      renderNotifications();
      openNotificationDetail(item);
    });
  });
}

function openNotificationDetail(item) {
  const style = NOTIF_TYPE_STYLE[item.type] || NOTIF_TYPE_STYLE.general;
  sheetPanel.innerHTML = `
    <div class="notif-detail-header">
      <span class="notif-type-icon" style="background:color-mix(in srgb, ${style.color} 12%, transparent); color:${style.color}">
        <svg viewBox="0 0 24 24"><use href="#${style.icon}"/></svg>
      </span>
      <div>
        <h3 style="margin:0">${t(n.titleKey) || t('notifDetailDefault')}</h3>
        <p style="margin:4px 0 0; font-size:12px; color:var(--text-muted)">${notifRelativeTime(n.minutesAgo)}</p>
      </div>
    </div>
    <p class="notif-detail-body">${t(n.bodyKey)}</p>
  `;
  sheetBackdrop.classList.add('open');
}

$('#bell-btn').addEventListener('click', () => {
  renderNotifications();
  notifScreenEl.classList.add('open');
});
$('#notif-back-btn').addEventListener('click', () => notifScreenEl.classList.remove('open'));
notifMarkAllBtn.addEventListener('click', () => {
  notifications = notifications.map((n) => ({ ...n, unread: false }));
  renderNotifications();
});

// Set the initial bell-badge state without building the full list yet.
bellDotEl.hidden = !notifications.some((n) => n.unread);
