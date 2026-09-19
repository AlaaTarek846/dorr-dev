import { defineAsyncComponent } from 'vue';
import { getFallbackThemePath, getThemePath } from './themeContext';

const shellModules = import.meta.glob('../layouts/themes/*/*Shell.vue');

const PORTAL_SHELL_NAMES = {
    admin: 'AdminShell',
    user: 'UserShell',
    provider: 'ProviderShell',
};

function shellKey(themePath, shellFileName) {
    return `../layouts/themes/${themePath}/${shellFileName}.vue`;
}

async function loadShellModule(portal) {
    const shellFileName = PORTAL_SHELL_NAMES[portal];

    if (! shellFileName) {
        throw new Error(`Unknown dashboard portal: ${portal}`);
    }

    const theme = getThemePath();
    const fallback = getFallbackThemePath();
    const primaryKey = shellKey(theme, shellFileName);
    const fallbackKey = shellKey(fallback, shellFileName);

    if (shellModules[primaryKey]) {
        return shellModules[primaryKey]();
    }

    if (shellModules[fallbackKey]) {
        return shellModules[fallbackKey]();
    }

    throw new Error(`Dashboard shell not found for portal "${portal}" (theme: ${theme})`);
}

export function resolveShell(portal) {
    return defineAsyncComponent(() => loadShellModule(portal));
}
