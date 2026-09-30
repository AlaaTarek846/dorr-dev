/**
 * Keep PrimeVue dark preset in sync with Ynex `data-theme-mode` on <html>.
 */
export function syncAppDarkClass(root = document.documentElement) {
    const isDark = root.getAttribute('data-theme-mode') === 'dark';

    root.classList.toggle('app-dark', isDark);
}

export function watchThemeMode(root = document.documentElement) {
    syncAppDarkClass(root);

    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.type === 'attributes' && mutation.attributeName === 'data-theme-mode') {
                syncAppDarkClass(root);
            }
        }
    });

    observer.observe(root, { attributes: true, attributeFilter: ['data-theme-mode'] });

    return observer;
}
