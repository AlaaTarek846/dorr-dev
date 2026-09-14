import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import i18n from './plugins/i18n';
import './api/adminAxios';
import './services/api';
import './services/auth.service';
import './stores/auth';
import './composables/useAuth';
import './composables/usePermission';

const app = createApp(App);

app.use(createPinia());
app.use(router);
app.use(i18n);

const mountEl = document.getElementById('app');

if (mountEl) {
    app.mount(mountEl);
}
