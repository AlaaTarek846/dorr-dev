import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import i18n, { setI18nLocale } from './plugins/i18n';
import { applyDocumentDirection, getStoredDirection, getStoredLocale } from './utils/direction';
import './api/adminAxios';
import './services/api';
import './services/auth.service';
import './stores/auth';
import './composables/useAuth';
import './composables/usePermission';
import './styles/catalog-list.css';

applyDocumentDirection(getStoredDirection());

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);
app.use(i18n);

setI18nLocale(getStoredLocale());

const mountEl = document.getElementById('app');

if (mountEl) {
    app.mount(mountEl);
}
