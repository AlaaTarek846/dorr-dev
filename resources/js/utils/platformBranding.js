export const DEFAULT_LOGO_PATHS = {
    light: {
        desktop: '/dashboard/assets/images/brand-logos/desktop-logo.png',
        toggle: '/dashboard/assets/images/brand-logos/toggle-logo.png',
        white: '/dashboard/assets/images/brand-logos/desktop-white.png',
        toggleWhite: '/dashboard/assets/images/brand-logos/toggle-white.png',
    },
    dark: {
        desktop: '/dashboard/assets/images/brand-logos/desktop-dark.png',
        toggle: '/dashboard/assets/images/brand-logos/toggle-dark.png',
    },
};

export const DEFAULT_FAVICON = '/dashboard/assets/images/brand-logos/favicon.ico';

export function resolveLightLogo(logo) {
    return logo || DEFAULT_LOGO_PATHS.light.desktop;
}

export function resolveDarkLogo(logoDark, logo) {
    return logoDark || logo || DEFAULT_LOGO_PATHS.dark.desktop;
}

function upsertLink(rel, href, attributes = {}) {
    if (! href) {
        return;
    }

    const selectorParts = [`link[rel="${rel}"]`];

    if (attributes.sizes) {
        selectorParts.push(`[sizes="${attributes.sizes}"]`);
    }

    let element = document.head.querySelector(selectorParts.join(''));

    if (! element) {
        element = document.createElement('link');
        element.rel = rel;
        document.head.appendChild(element);
    }

    element.href = href;

    if (attributes.type) {
        element.type = attributes.type;
    }

    if (attributes.sizes) {
        element.sizes = attributes.sizes;
    }
}

export function applyDocumentBranding(branding, titlePrefix = 'Admin') {
    const appName = branding?.app_name || 'Laravel';

    document.title = `${titlePrefix} | ${appName}`;

    const favicon = branding?.favicon_ico || branding?.favicon_32 || branding?.favicon_16 || DEFAULT_FAVICON;

    upsertLink('icon', favicon, {
        type: favicon.endsWith('.ico') ? 'image/x-icon' : undefined,
    });

    upsertLink('icon', branding?.favicon_32, { type: 'image/png', sizes: '32x32' });
    upsertLink('icon', branding?.favicon_16, { type: 'image/png', sizes: '16x16' });
    upsertLink('apple-touch-icon', branding?.apple_touch_icon);
    upsertLink('manifest', branding?.web_manifest);
}
