import './bootstrap';

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import ProviderApp from './ProviderApp.vue';
import router from './router/provider-index';
import i18n, { setI18nLocale } from './plugins/i18n';
import {
    applyDocumentDirection,
    getStoredDirection,
    hasStoredLocalePreference,
    resolveInitialLocale,
} from './utils/direction';
import './api/providerAxios';
import './styles/catalog-list.css';

applyDocumentDirection(
    getStoredDirection(),
    resolveInitialLocale(),
    hasStoredLocalePreference(),
);

const app = createApp(ProviderApp);
const pinia = createPinia();

app.use(pinia);
app.use(router);
app.use(i18n);

setI18nLocale(resolveInitialLocale());

const mountEl = document.getElementById('app');

if (mountEl) {
    document.documentElement.classList.add('provider-app-ready');
    app.mount(mountEl);
}
