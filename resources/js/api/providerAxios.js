import axios from 'axios';

const providerAxios = axios.create({
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    },
});

providerAxios.interceptors.request.use((config) => {
    const token = localStorage.getItem('provider_token');
    const locale = localStorage.getItem('provider_locale') || localStorage.getItem('admin_locale');

    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }

    if (locale) {
        config.headers['X-Locale'] = locale;
        config.headers['Accept-Language'] = locale;
    }

    if (config.data instanceof FormData) {
        delete config.headers['Content-Type'];
    }

    return config;
});

export default providerAxios;
