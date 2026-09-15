import { onMounted } from 'vue';
import { useAvailableLanguagesStore } from '../stores/availableLanguages';
import { useLocaleStore } from '../stores/locale';
import { getStoredDirection, syncBootstrapStylesheet } from '../utils/direction';

function patchThemeDirectionHandlers() {
    const localeStore = useLocaleStore();
    const languagesStore = useAvailableLanguagesStore();

    window.rtlFn = () => {
        const rtlLanguage = languagesStore.items.find((language) => language.direction === 'rtl');

        if (rtlLanguage) {
            localeStore.setLocale(rtlLanguage.code);
        }
    };

    window.ltrFn = () => {
        const ltrLanguage = languagesStore.items.find((language) => language.direction === 'ltr');

        if (ltrLanguage) {
            localeStore.setLocale(ltrLanguage.code);
        }
    };
}

function loadScript(src) {
    return new Promise((resolve, reject) => {
        if (document.querySelector(`script[data-dashboard="${src}"]`)) {
            resolve();

            return;
        }

        const script = document.createElement('script');

        script.src = src;
        script.dataset.dashboard = src;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error(`Failed to load ${src}`));
        document.body.appendChild(script);
    });
}

export function useDashboard() {
    onMounted(async () => {
        const localeStore = useLocaleStore();

        await localeStore.ensureValidLocale();

        document.getElementById('loader')?.classList.add('d-none');

        const scripts = [
            '/dashboard/assets/libs/node-waves/waves.min.js',
            '/dashboard/assets/libs/simplebar/simplebar.min.js',
            '/dashboard/assets/js/simplebar.js',
            '/dashboard/assets/js/defaultmenu.min.js',
            '/dashboard/assets/js/sticky.js',
            '/dashboard/assets/js/custom-switcher.min.js',
        ];

        for (const src of scripts) {
            try {
                await loadScript(src);
                syncBootstrapStylesheet();
            } catch {
                // Dashboard scripts are optional during incremental setup.
            }
        }

        patchThemeDirectionHandlers();
        syncBootstrapStylesheet();
        initScrollToTop();
        bindDirectionSwitcher();
        closeOpenHeaderDropdowns();

        if (typeof window.Waves !== 'undefined') {
            window.Waves.attach('.btn-wave', ['waves-light']);
            window.Waves.init();
        }
    });
}

function bindDirectionSwitcher() {
    const rtlInput = document.querySelector('#switcher-rtl');
    const ltrInput = document.querySelector('#switcher-ltr');

    rtlInput?.addEventListener('change', () => {
        if (rtlInput.checked) {
            window.rtlFn?.();
        }
    });

    ltrInput?.addEventListener('change', () => {
        if (ltrInput.checked) {
            window.ltrFn?.();
        }
    });
}

function closeOpenHeaderDropdowns() {
    document.querySelectorAll('.header-element .dropdown-menu.show').forEach((menu) => {
        menu.classList.remove('show');
    });

    document.querySelectorAll('.header-element .dropdown-toggle.show').forEach((toggle) => {
        toggle.classList.remove('show');
        toggle.setAttribute('aria-expanded', 'false');
    });
}

function initScrollToTop() {
    const scrollToTop = document.querySelector('.scrollToTop');

    if (! scrollToTop) {
        return;
    }

    window.addEventListener('scroll', () => {
        scrollToTop.style.display = window.scrollY > 100 ? 'flex' : 'none';
    });

    scrollToTop.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}
