import { createApp } from 'vue';
import { createPinia } from 'pinia';
import PrimeVue from 'primevue/config';
import Aura from '@primevue/themes/aura';
import App from './App.vue';
import router from './router';
import i18n, { setI18nLocale } from './plugins/i18n';
import {
    applyDocumentDirection,
    getStoredDirection,
    hasStoredLocalePreference,
    resolveInitialLocale,
} from './utils/direction';
import './api/adminAxios';
import './services/api';
import './services/auth.service';
import './stores/auth';
import './composables/useAuth';
import './composables/usePermission';
import './styles/catalog-list.css';

applyDocumentDirection(
    getStoredDirection(),
    resolveInitialLocale(),
    hasStoredLocalePreference(),
);

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);
app.use(i18n);
app.use(PrimeVue, {
    theme: {
        preset: Aura,
        options: {
            prefix: 'p',
            darkModeSelector: '.app-dark',
        },
    },
    zIndex: {
        overlay: 2000,
    },
});

setI18nLocale(resolveInitialLocale());

const mountEl = document.getElementById('app');

if (mountEl) {
    document.documentElement.classList.add('admin-app-ready');
    app.mount(mountEl);
}
