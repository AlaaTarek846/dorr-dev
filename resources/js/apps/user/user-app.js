import '../../bootstrap';

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import UserApp from './UserApp.vue';
import router from '../../router/user-index';
import i18n, { setI18nLocale } from '../../plugins/i18n';
import {
    applyDocumentDirection,
    getStoredDirection,
    hasStoredLocalePreference,
    resolveInitialLocale,
} from '../../utils/direction';
import '../../api/userAxios';
import '../../styles/catalog-list.css';

applyDocumentDirection(
    getStoredDirection(),
    resolveInitialLocale(),
    hasStoredLocalePreference(),
);

const app = createApp(UserApp);
const pinia = createPinia();

app.use(pinia);
app.use(router);
app.use(i18n);

setI18nLocale(resolveInitialLocale());

const mountEl = document.getElementById('app');

if (mountEl) {
    document.documentElement.classList.add('user-app-ready');
    app.mount(mountEl);
}

