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
const dialCodeEl = $('#phone-dial-code');

// Defaults match the backend's fallback (seeded default country) — only
// used until /user/v1/countries/detect answers, or if the fetch fails.
let dialCode = '+20';
let phoneLength = 10;

fetch('/api/user/v1/countries/detect', { headers: { Accept: 'application/json', 'X-Locale': 'ar' } })
  .then((r) => r.json())
  .then((res) => {
    if (!res?.data) return;
    dialCode = res.data.dial_code || dialCode;
    phoneLength = res.data.phone_length || phoneLength;
    dialCodeEl.textContent = dialCode;
    phoneInput.maxLength = phoneLength;
    phoneInput.placeholder = '0'.repeat(phoneLength);
  })
  .catch(() => {
    // Offline or backend unreachable — keep the defaults above.
  });

phoneInput.addEventListener('input', () => {
  phoneInput.value = phoneInput.value.replace(/\D/g, '').slice(0, phoneLength);
  sendBtn.disabled = phoneInput.value.length !== phoneLength;
});

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
  otpTimerLabel.textContent = `إعادة الإرسال بعد ${countdown} ثانية`;
  otpTimerProgress.style.strokeDashoffset = 0;

  otpTimerInterval = setInterval(() => {
    countdown--;
    otpCountdownEl.textContent = countdown;
    otpTimerLabel.textContent = `إعادة الإرسال بعد ${countdown} ثانية`;
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
  sendBtn.disabled = phoneInput.value.length !== phoneLength;
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

// RTL page: translate the other direction so slides advance visually correctly
const isRtl = document.documentElement.dir === 'rtl';
function goToSlide(i) {
  bannerIndex = i;
  track.style.transform = `translateX(${isRtl ? i * 100 : -i * 100}%)`;
  $$('span', dotsEl).forEach((d, idx) => d.classList.toggle('active', idx === i));
}

setInterval(() => {
  goToSlide((bannerIndex + 1) % slideCount);
}, 5000);

// ===================== Profile menu (staggered slide-in) =====================
const ICONS = {
  person: 'icon-person', bell: 'icon-bell',
  language: 'icon-language', 'dark-mode': 'icon-dark-mode', help: 'icon-help',
  phone: 'icon-phone', info: 'icon-info', shield: 'icon-shield',
  logout: 'icon-logout', delete: 'icon-delete',
};

let profileRendered = false;
function renderProfileMenu() {
  if (profileRendered) return;
  profileRendered = true;
  const rows = $$('.menu-row', $('#profile-menu'));
  rows.forEach((row, index) => {
    const iconId = ICONS[row.dataset.icon] || 'icon-info';
    const label = row.dataset.label;
    const isSwitch = row.dataset.switch === '1';

    row.innerHTML = `
      <svg class="menu-icon" viewBox="0 0 24 24"><use href="#${iconId}"/></svg>
      <span class="menu-label">${label}</span>
      ${isSwitch ? '<span class="toggle"></span>' : '<svg class="menu-chevron" viewBox="0 0 24 24"><use href="#icon-chevron"/></svg>'}
    `;
    row.style.animationDelay = `${index * 40}ms`;

    if (isSwitch) {
      const toggle = $('.toggle', row);
      row.addEventListener('click', () => toggle.classList.toggle('on'));
      return;
    }

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
        $('#profile-menu').innerHTML = ORIGINAL_MENU_HTML;
        showScreen('login');
        phoneInput.value = '';
        sendBtn.disabled = true;
      }
    }
  }
}

// Snapshot of the original (unrendered) menu-row markup, so logout can reset
// the DOM and replay the stagger animation on the next login.
const ORIGINAL_MENU_HTML = $('#profile-menu').innerHTML;

// ===================== Sub-screens (Personal data / Notifications / Privacy) =====================
const subScreenEl = $('#sub-screen');
const subTitleEl = $('#sub-title');
const subBodyEl = $('#sub-body');

const SUB_SCREENS = {
  'personal-data': {
    title: 'البيانات الشخصية',
    render: () => `
      <div class="form-field"><label>الاسم (عربي)</label><input type="text" placeholder="اسمك بالعربي"></div>
      <div class="form-field"><label>الاسم (إنجليزي)</label><input type="text" placeholder="Your name"></div>
      <div class="form-field"><label>البريد الإلكتروني</label><input type="email" placeholder="name@example.com"></div>
      <button class="btn-primary" id="save-personal-data"><span class="btn-label">حفظ</span></button>
    `,
    afterRender: () => {
      $('#save-personal-data').addEventListener('click', () => {
        closeSubScreen();
        showToast('تم حفظ التعديلات');
      });
    },
  },
  notifications: {
    title: 'الإشعارات',
    render: () => `
      ${notifToggleRow('الإشعارات الفورية', 'استقبل إشعارات على هذا الجهاز', true)}
      ${notifToggleRow('تحديثات الطلبات والخدمة', 'تغييرات حالة طلباتك', true)}
      ${notifToggleRow('العروض والتخفيضات', 'أخبار وعروض خاصة', true)}
      ${notifToggleRow('إشعارات البريد الإلكتروني', 'استقبل التحديثات على بريدك أيضاً', false)}
    `,
    afterRender: (root) => {
      $$('.toggle', root).forEach((t) => t.addEventListener('click', () => t.classList.toggle('on')));
    },
  },
  privacy: {
    title: 'سياسة الخصوصية',
    render: () => `<p class="privacy-text">هذا نص مبدئي لسياسة الخصوصية. استبدله بسياستك الحقيقية قبل الإطلاق: ما هي البيانات التي تجمعها، لماذا تجمعها، كيف يتم تخزينها، مع من تتم مشاركتها، وكيف يمكن للمستخدم طلب حذف بياناته.\n\nحتى ذلك الحين، هذه الشاشة موجودة فقط لتوضيح شكل وتنسيق صفحة محتوى ثابت طويلة.</p>`,
  },
};

function notifToggleRow(title, desc, on) {
  return `
    <div class="toggle-setting">
      <div class="ts-text"><div class="ts-title">${title}</div><div class="ts-desc">${desc}</div></div>
      <span class="toggle ${on ? 'on' : ''}"></span>
    </div>
  `;
}

function openSubScreen(type) {
  const cfg = SUB_SCREENS[type];
  subTitleEl.textContent = cfg.title;
  subBodyEl.innerHTML = cfg.render();
  cfg.afterRender?.(subBodyEl);
  subScreenEl.classList.add('open');
}
function closeSubScreen() {
  subScreenEl.classList.remove('open');
}
$('#sub-back-btn').addEventListener('click', closeSubScreen);

// ===================== Bottom sheets (FAQ / Contact us) =====================
const sheetBackdrop = $('#sheet-backdrop');
const sheetPanel = $('#sheet-panel');

const FAQ_ITEMS = [
  ['إزاي أقدر أعدل بياناتي الشخصية؟', 'افتح الحساب ← البيانات الشخصية، عدّل بياناتك، ثم اضغط حفظ.'],
  ['إزاي أغيّر لغة التطبيق؟', 'افتح الحساب ← اللغة واختر العربية أو الإنجليزية.'],
  ['التطبيق بيدعم الوضع الليلي؟', 'أيوة — فعّله من الحساب ← الوضع الليلي.'],
  ['إزاي أتواصل مع الدعم؟', 'افتح الحساب ← تواصل معنا للاتصال أو واتساب أو إيميل.'],
];

const SHEETS = {
  faq: {
    render: () => `
      <h3>الأسئلة الشائعة</h3>
      ${FAQ_ITEMS.map(([q, a]) => `
        <div class="faq-item">
          <div class="faq-q">${q}</div>
          <div class="faq-a">${a}</div>
        </div>
      `).join('')}
    `,
    afterRender: (root) => {
      $$('.faq-item', root).forEach((item) => item.addEventListener('click', () => item.classList.toggle('open')));
    },
  },
  contact: {
    render: () => `
      <h3>تواصل معنا</h3>
      ${contactTile('icon-phone', 'اتصال هاتفي', '+96522200000', 'tel:+96522200000')}
      ${contactTile('icon-chat', 'واتساب', '96522200000', 'https://wa.me/96522200000')}
      ${contactTile('icon-email', 'البريد الإلكتروني', 'support@dorr.app', 'mailto:support@dorr.app')}
    `,
  },
};

function contactTile(icon, label, value, href) {
  return `
    <a class="contact-tile" href="${href}" target="_blank" rel="noopener">
      <span class="ct-icon"><svg viewBox="0 0 24 24"><use href="#${icon}"/></svg></span>
      <span><span class="ct-label">${label}</span><br><span class="ct-value">${value}</span></span>
    </a>
  `;
}

function openSheet(type) {
  const cfg = SHEETS[type];
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
      <h3>درر</h3>
      <p>الإصدار 1.0.0<br><br>تطبيق عام لإدارة الخدمات. هذه الشاشة، زي باقي التطبيق، نقطة بداية تقدر تخصصها حسب نشاطك.</p>
      <button class="dialog-close" data-close>إغلاق</button>
    `,
  },
  language: {
    render: () => `
      <h3>اللغة</h3>
      <label class="lang-option"><input type="radio" name="lang" checked> العربية</label>
      <label class="lang-option"><input type="radio" name="lang"> English — إنجليزي</label>
      <button class="dialog-close" data-close>إغلاق</button>
    `,
  },
};

function openDialog(type) {
  dialogBox.innerHTML = DIALOGS[type].render();
  $('[data-close]', dialogBox).addEventListener('click', closeDialog);
  dialogBackdrop.classList.add('open');
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
const bellDotEl = $('.bell-dot');

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
