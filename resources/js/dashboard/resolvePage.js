import { getFallbackThemePath, getThemePath } from './themeContext';

const themedPageModules = import.meta.glob('../modules/*/themes/*/views/**/*.vue');

function themedPageKey(portal, themePath, viewPath) {
    return `../modules/${portal}/themes/${themePath}/views/${viewPath}.vue`;
}

export function resolvePage(portal, viewPath) {
    return () => {
        const theme = getThemePath();
        const fallback = getFallbackThemePath();
        const primaryKey = themedPageKey(portal, theme, viewPath);
        const fallbackKey = themedPageKey(portal, fallback, viewPath);

        if (themedPageModules[primaryKey]) {
            return themedPageModules[primaryKey]();
        }

        if (themedPageModules[fallbackKey]) {
            return themedPageModules[fallbackKey]();
        }

        throw new Error(`Dashboard page not found: ${portal}/${viewPath} (theme: ${theme})`);
    };
}
