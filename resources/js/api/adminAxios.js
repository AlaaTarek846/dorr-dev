import axios from 'axios';

const adminAxios = axios.create({
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    },
});

adminAxios.interceptors.request.use((config) => {
    const token = localStorage.getItem('admin_token');
    const locale = localStorage.getItem('admin_locale');

    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }

    if (locale) {
        config.headers['X-Locale'] = locale;
    }

    if (config.data instanceof FormData) {
        delete config.headers['Content-Type'];
    }

    return config;
});

export default adminAxios;
