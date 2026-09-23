// Design preview — mostly mirrors the screens/animations built in the real
// Kotlin/Compose project (androidApp/), but the country detection below is a
// REAL call to the Laravel backend (same origin, dorr.test), so this page
// doubles as a way to sanity-check that API before wiring it into Kotlin.

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
  },
};

let languages = [];
let selectedLanguageCode = 'ar';

function syncSendEnabled() {
  sendBtn.disabled = phoneInput.value.length !== phoneLength || !termsCheck.checked;
}

// Seeded default country is Saudi Arabia until detect/dropdown answer.
let dialCode = '+966';
let phoneLength = 9;
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
  dialText.textContent = dialCode;
  flagEl.src = flagUrl(flagCode);
  flagEl.alt = flagCode;
  phoneInput.maxLength = phoneLength;
  phoneInput.placeholder = '0'.repeat(phoneLength);
  phoneInput.value = phoneInput.value.replace(/\D/g, '').slice(0, phoneLength);
  syncSendEnabled();
  renderCountryMenu();
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
  if (localeChanged) {
    loadLanguages();
    loadCountries(false);
  }
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

loadLanguages();
loadCountries(true);
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

function loadCountries(detect) {
  const headers = { ...apiHeaders };
  const requests = [
    fetch('/api/general/v1/countries/dropdown', { headers }).then((r) => r.json()).catch(() => null),
  ];
  if (detect) {
    requests.push(fetch('/api/general/v1/countries/detect', { headers }).then((r) => r.json()).catch(() => null));
  }
  return Promise.all(requests)
    .then(([listRes, detectRes]) => {
      const listed = Array.isArray(listRes?.data) ? listRes.data : [];
      if (listed.length) countries = listed;
      if (!detect) {
        renderCountryMenu();
        return;
      }
      const detected = detectRes?.data;
      const chosen = (detected && (countries.find((item) => item.id === detected.id) || detected))
        || countries.find((item) => item.is_default)
        || countries[0];
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
  { icon: 'person', action: 'personal-data', title: 'البيانات الشخصية', subtitle: 'الاسم والهاتف والبريد' },
  { icon: 'bell', action: 'notifications', title: 'الإشعارات', subtitle: 'التحكم في التنبيهات' },
  { icon: 'language', action: 'language', title: 'اللغة', subtitle: 'لغة واجهة التطبيق' },
  { icon: 'dark-mode', switch: true, title: 'الوضع الليلي', subtitle: 'مظهر داكن للتطبيق' },
  { icon: 'help', action: 'faq', title: 'الأسئلة الشائعة', subtitle: 'إجابات سريعة' },
  { icon: 'phone', action: 'contact', title: 'تواصل معنا', subtitle: 'هاتف وواتساب وبريد' },
  { icon: 'info', action: 'about', title: 'عن التطبيق', subtitle: 'الإصدار والمعلومات' },
  { icon: 'shield', action: 'privacy', title: 'سياسة الخصوصية', subtitle: 'كيف نتعامل مع بياناتك' },
  { icon: 'logout', action: 'logout', title: 'تسجيل خروج', subtitle: 'الخروج من هذا الجهاز', danger: true },
  { icon: 'delete', action: 'delete', title: 'حذف الحساب', subtitle: 'إجراء لا يمكن التراجع عنه', danger: true },
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
        <span class="account-link-text"><strong>${item.title}</strong><small>${item.subtitle}</small></span>
        ${trail}
      </button>`;
  }).join('');
}

let profileRendered = false;
function renderProfileMenu() {
  if (profileRendered) return;
  profileRendered = true;
  const menu = $('#profile-menu');
  menu.innerHTML = settingsMenuHtml();
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
        ? 'هل أنت متأكد من تسجيل الخروج؟'
        : 'سيتم حذف حسابك وكل بياناتك نهائياً. هل أنت متأكد؟';
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
  showToast('إضافة الرصيد لسه مش متوصلة في النسخة التجريبية');
});

$$('[data-account-link]').forEach((button) => {
  button.addEventListener('click', () => {
    const link = button.dataset.accountLink;
    if (link === 'personal-data') openSubScreen('personal-data');
    else if (link === 'settings') openAccountSettings();
    else if (link === 'help') openSheet('faq');
    else showToast('قريباً');
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
  if (value === 'male') return 'ذكر';
  if (value === 'female') return 'أنثى';
  return 'غير محدد';
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

function paintProfileSurfaces() {
  const nameEl = $('#profile-name');
  if (nameEl) nameEl.textContent = profileState.name;
  const homeGreeting = $('#home-greeting');
  if (homeGreeting) homeGreeting.textContent = `مرحباً، ${profileState.name}`;
  const contact = $('#profile-contact');
  if (contact) contact.textContent = profilePhoneText();
}

function clearProfileOtpTimer() {
  clearInterval(profileOtpTimer);
  profileOtpTimer = null;
}

const verifiedMark = '<svg class="profile-edit-check" viewBox="0 0 24 24" aria-label="موثّق"><circle cx="12" cy="12" r="10"/><path d="M7.2 12.2l3.1 3.1 6.5-6.6"/></svg>';

function profileOtpMarkup(target) {
  const boxes = Array.from({ length: 6 }, (_, i) =>
    `<input type="tel" class="otp-box profile-otp-box" maxlength="1" inputmode="numeric" autocomplete="one-time-code">`
  ).join('');
  return `
    <div class="profile-form">
      <div class="profile-form-card profile-otp">
        <p class="profile-form-hint">أدخل كود التأكيد المرسل إلى <bdi dir="ltr">${escapeHtml(target)}</bdi>، وبعد التأكيد يتم حفظ التعديل.</p>
        <div class="otp-boxes" id="profile-otp-boxes" dir="ltr">${boxes}</div>
        <p class="profile-otp-timer" id="profile-otp-timer">إعادة الإرسال بعد <span id="profile-otp-count">60</span> ثانية</p>
        <button type="button" class="profile-otp-resend" id="profile-otp-resend" hidden>إعادة إرسال الكود</button>
        <p class="profile-dev-hint">نسخة تجريبية: الكود 123456</p>
      </div>
      <button type="button" class="btn-primary" id="profile-otp-confirm" disabled><span class="btn-label">تأكيد</span></button>
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
      showToast('الكود غير صحيح');
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
    showToast('تم إرسال الكود');
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
    title: 'البيانات الشخصية',
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
            ${field('name', '<svg viewBox="0 0 24 24"><use href="#icon-person"/></svg>', 'الاسم', escapeHtml(profileState.name), false)}
            ${field('gender', '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.2"/><path d="M6.5 19.5c.4-2.8 2.6-4.8 5.5-4.8s5.1 2 5.5 4.8"/></svg>', 'الجنس', escapeHtml(genderLabel(profileState.gender)), false)}
            ${field('phone', '<svg viewBox="0 0 24 24"><use href="#icon-phone"/></svg>', 'رقم الهاتف', phone ? `<span dir="ltr">${escapeHtml(phone)}</span>` : 'غير مضاف', Boolean(phone))}
            ${field('email', '<svg viewBox="0 0 24 24"><use href="#icon-email"/></svg>', 'البريد الإلكتروني', email ? escapeHtml(email) : 'غير مضاف', Boolean(email))}
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
    title: 'تعديل الاسم',
    render: () => `
      <div class="profile-form">
        <div class="profile-form-card">
          <label class="profile-field-label" for="profile-name-input">الاسم</label>
          <div class="profile-icon-field">
            <span class="profile-input-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><use href="#icon-person"/></svg></span>
            <input id="profile-name-input" type="text" maxlength="80" value="${escapeHtml(profileState.name)}">
          </div>
          <p class="profile-form-hint">اكتب الاسم كما يظهر في حسابك.</p>
        </div>
        <button type="button" class="btn-primary" id="profile-save-name"><span class="btn-label">حفظ</span></button>
      </div>`,
    afterRender: (root) => {
      const input = $('#profile-name-input', root);
      $('#profile-save-name', root).addEventListener('click', () => {
        const value = input.value.trim();
        if (value.length < 2) {
          showToast('اكتب الاسم');
          input.focus();
          return;
        }
        profileState.name = value;
        paintProfileSurfaces();
        showToast('تم تحديث الاسم');
        closeSubScreen();
      });
      input.focus();
    },
  },
  'edit-gender': {
    title: 'تعديل الجنس',
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
            <p class="profile-field-label">الجنس</p>
            <div class="gender-options">
              ${option('male', 'ذكر')}
              ${option('female', 'أنثى')}
            </div>
            <p class="profile-form-hint">اختر الجنس ثم احفظ.</p>
          </div>
          <button type="button" class="btn-primary" id="profile-save-gender"><span class="btn-label">حفظ</span></button>
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
          showToast('اختر الجنس');
          return;
        }
        profileState.gender = selected;
        showToast('تم تحديث الجنس');
        closeSubScreen();
      });
    },
  },
  'edit-phone': {
    title: 'تعديل رقم الهاتف',
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
          <label class="profile-field-label" for="profile-phone-input">رقم الهاتف</label>
          <div class="phone-field">
            <span class="phone-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M7 3.5h2.2l1.2 3-1.7 1a11 11 0 0 0 5.8 5.8l1-1.7 3 1.2V15a2 2 0 0 1-2.2 2A13.5 13.5 0 0 1 5 7.7 2 2 0 0 1 7 3.5z"/></svg>
            </span>
            <span class="phone-sep"></span>
            <div class="phone-code-wrap" id="profile-phone-wrap">
              <button type="button" class="phone-code" id="profile-dial-btn" aria-haspopup="listbox" aria-label="كود الدولة">
                <img class="phone-flag" id="profile-flag" alt="" width="20" height="15" src="${flagUrl(flag)}">
                <span id="profile-dial-text">${escapeHtml(dial)}</span>
                <span class="phone-code-caret" aria-hidden="true"></span>
              </button>
              <div class="phone-code-menu" id="profile-phone-menu" hidden>
                <label class="phone-code-search">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                  <input type="search" id="profile-country-search" placeholder="ابحث عن دولة" autocomplete="off" aria-label="بحث عن دولة">
                </label>
                <ul id="profile-country-list"></ul>
                <p class="phone-code-empty" id="profile-country-empty" hidden>لا توجد نتائج</p>
              </div>
            </div>
            <span class="phone-sep"></span>
            <input type="tel" id="profile-phone-input" maxlength="${length}" placeholder="${'0'.repeat(length)}" inputmode="numeric" value="${escapeHtml(contactDraft.value || '')}">
          </div>
          <p class="profile-form-hint">اكتب الرقم الجديد. بنرسل كود تأكيد، والتعديل يتم بعد التأكد.</p>
          </div>
          <button type="button" class="btn-primary" id="profile-send-code"><span class="btn-label">إرسال الكود</span></button>
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
          finishContactEdit('تم تحديث رقم الهاتف');
        });
        return;
      }
      bindProfilePhone(root);
      const input = $('#profile-phone-input', root);
      $('#profile-send-code', root).addEventListener('click', () => {
        const length = contactDraft.phoneLength || phoneLength;
        const digits = input.value.replace(/\D/g, '');
        if (digits.length !== length) {
          showToast('اكتب رقم هاتف صحيح');
          input.focus();
          return;
        }
        contactDraft.value = digits;
        contactDraft.step = 'otp';
        paintSubScreen();
        showToast('تم إرسال الكود');
      });
      input.focus();
    },
  },
  'edit-email': {
    title: 'تعديل البريد الإلكتروني',
    render: () => {
      if (contactDraft.step === 'otp') return profileOtpMarkup(contactDraft.value);
      return `
        <div class="profile-form">
          <div class="profile-form-card">
            <label class="profile-field-label" for="profile-email-input">البريد الإلكتروني</label>
            <div class="profile-icon-field">
              <span class="profile-input-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><use href="#icon-email"/></svg></span>
              <input id="profile-email-input" type="email" inputmode="email" maxlength="120" dir="ltr" value="${escapeHtml(profileState.email)}" placeholder="name@example.com">
            </div>
            <p class="profile-form-hint">اكتب البريد الجديد. بنرسل كود تأكيد، والتعديل يتم بعد التأكد.</p>
          </div>
          <button type="button" class="btn-primary" id="profile-send-email"><span class="btn-label">إرسال الكود</span></button>
        </div>`;
    },
    afterRender: (root) => {
      if (contactDraft.step === 'otp') {
        bindProfileOtp(root, () => {
          profileState.email = contactDraft.value;
          finishContactEdit('تم تحديث البريد الإلكتروني');
        });
        return;
      }
      const input = $('#profile-email-input', root);
      $('#profile-send-email', root).addEventListener('click', () => {
        const email = input.value.trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          showToast('اكتب بريد إلكتروني صحيح');
          input.focus();
          return;
        }
        contactDraft.value = email;
        contactDraft.step = 'otp';
        paintSubScreen();
        showToast('تم إرسال الكود');
      });
      input.focus();
    },
  },
  notifications: {
    title: 'الإشعارات',
    render: () => `
      <div class="profile-form">
        <div class="profile-form-card notif-settings">
          ${notifToggleRow('icon-bell', 'الإشعارات الفورية', 'استقبل إشعارات على هذا الجهاز', true)}
          ${notifToggleRow('icon-receipt', 'تحديثات الطلبات والخدمة', 'تغييرات حالة طلباتك', true)}
          ${notifToggleRow('icon-tag', 'العروض والتخفيضات', 'أخبار وعروض خاصة', true)}
          ${notifToggleRow('icon-email', 'إشعارات البريد الإلكتروني', 'استقبل التحديثات على بريدك أيضاً', false)}
        </div>
      </div>`,
    afterRender: (root) => {
      $$('.toggle', root).forEach((t) => {
        t.addEventListener('click', (event) => {
          event.stopPropagation();
          t.classList.toggle('on');
        });
      });
    },
  },
  privacy: {
    title: 'سياسة الخصوصية',
    render: () => `
      <div class="profile-form">
        <div class="profile-form-card privacy-card">
          <div class="content-head">
            <span class="account-link-icon"><svg viewBox="0 0 24 24"><use href="#icon-shield"/></svg></span>
            <strong>خصوصيتك مهمة</strong>
          </div>
          <p class="privacy-text">هذا نص مبدئي لسياسة الخصوصية. استبدله بسياستك الحقيقية قبل الإطلاق: ما هي البيانات التي تجمعها، لماذا تجمعها، كيف يتم تخزينها، مع من تتم مشاركتها، وكيف يمكن للمستخدم طلب حذف بياناته.\n\nحتى ذلك الحين، هذه الشاشة موجودة فقط لتوضيح شكل وتنسيق صفحة محتوى ثابت طويلة.</p>
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
  subTitleEl.textContent = cfg.title;
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

const FAQ_ITEMS = [
  ['إزاي أقدر أعدل بياناتي الشخصية؟', 'افتح الحساب ← البيانات الشخصية، ثم اختر الحقل اللي عاوز تعدّله.'],
  ['إزاي أغيّر لغة التطبيق؟', 'افتح الحساب ← اللغة واختر العربية أو الإنجليزية.'],
  ['التطبيق بيدعم الوضع الليلي؟', 'أيوة — فعّله من الحساب ← الوضع الليلي.'],
  ['إزاي أتواصل مع الدعم؟', 'افتح الحساب ← تواصل معنا للاتصال أو واتساب أو إيميل.'],
];

const SHEETS = {
  faq: {
    render: () => `
      <div class="sheet-head">
        <span class="account-link-icon"><svg viewBox="0 0 24 24"><use href="#icon-help"/></svg></span>
        <h3>الأسئلة الشائعة</h3>
      </div>
      <div class="sheet-list">
      ${FAQ_ITEMS.map(([q, a]) => `
        <div class="faq-item">
          <span class="account-link-icon faq-icon"><svg viewBox="0 0 24 24"><use href="#icon-help"/></svg></span>
          <div class="faq-copy">
            <div class="faq-q">${q}</div>
            <div class="faq-a">${a}</div>
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
        <h3>تواصل معنا</h3>
      </div>
      <div class="sheet-list">
      ${contactTile('icon-phone', 'اتصال هاتفي', '+96522200000', 'tel:+96522200000')}
      ${contactTile('icon-chat', 'واتساب', '96522200000', 'https://wa.me/96522200000')}
      ${contactTile('icon-email', 'البريد الإلكتروني', 'support@dorr.app', 'mailto:support@dorr.app')}
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
        <h3>درر</h3>
      </div>
      <p>الإصدار 1.0.0<br><br>تطبيق عام لإدارة الخدمات. هذه الشاشة، زي باقي التطبيق، نقطة بداية تقدر تخصصها حسب نشاطك.</p>
      <button class="dialog-close" data-close>إغلاق</button>
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
      <h3>اللغة</h3>
    </div>`;
  if (!languages.length) {
    return `${head}
      <p class="lang-loading">جاري تحميل اللغات…</p>
      <button class="dialog-close" data-close>إغلاق</button>`;
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
    <button class="dialog-close" data-close>إغلاق</button>`;
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
        dialogBox.querySelector('.lang-loading').textContent = 'تعذّر تحميل اللغات';
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
  { id: '1', type: 'job_update', title: 'تحديث حالة طلبك', body: 'طلب الخدمة بتاعك بقى "قيد التنفيذ".', minutesAgo: 5, unread: true },
  { id: '2', type: 'quote', title: 'عرض سعر جاهز للمراجعة', body: 'راجع البنود واعتمد العرض للمتابعة.', minutesAgo: 40, unread: true },
  { id: '3', type: 'invoice', title: 'فاتورة جديدة', body: 'تم إصدار فاتورة لآخر خدمة طلبتها.', minutesAgo: 180, unread: false },
  { id: '4', type: 'maintenance', title: 'صيانة قادمة', body: 'في فحص دوري مستحق قريبًا.', minutesAgo: 1500, unread: false },
  { id: '5', type: 'promo', title: 'عرض لفترة محدودة', body: 'عندنا عرض خاص الأسبوع ده.', minutesAgo: 4000, unread: false },
  { id: '6', type: 'general', title: 'أهلاً بيك في التطبيق', body: 'شكرًا لتسجيلك معانا.', minutesAgo: 10000, unread: false },
];

function notifRelativeTime(minutesAgo) {
  if (minutesAgo < 1) return 'الآن';
  if (minutesAgo < 60) return `منذ ${minutesAgo} دقيقة`;
  if (minutesAgo < 24 * 60) return `منذ ${Math.floor(minutesAgo / 60)} ساعة`;
  if (minutesAgo < 2 * 24 * 60) return 'منذ يوم';
  if (minutesAgo < 7 * 24 * 60) return `منذ ${Math.floor(minutesAgo / (24 * 60))} يوم`;
  return `منذ ${Math.floor(minutesAgo / (7 * 24 * 60))} أسابيع`;
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
        <h3>لا توجد إشعارات</h3>
        <p>ستظهر هنا الإشعارات عند وجود تحديثات جديدة</p>
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
            <span class="notif-card-title">${n.title}</span>
            <span class="notif-card-time">${notifRelativeTime(n.minutesAgo)}</span>
          </div>
          <p class="notif-card-excerpt">${n.body}</p>
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
        <h3 style="margin:0">${item.title || 'إشعار'}</h3>
        <p style="margin:4px 0 0; font-size:12px; color:var(--text-muted)">${notifRelativeTime(item.minutesAgo)}</p>
      </div>
    </div>
    <p class="notif-detail-body">${item.body}</p>
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
