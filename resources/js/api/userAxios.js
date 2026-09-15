import axios from 'axios';

const userAxios = axios.create({
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    },
});

userAxios.interceptors.request.use((config) => {
    const token = localStorage.getItem('user_token');
    const locale = localStorage.getItem('user_locale') || localStorage.getItem('admin_locale');

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

export default userAxios;
